<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Support\PaymentMethods;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Přijetí platby s rozhodnutím o evidenci tržby (společný příkaz pro web i mobil).
 *
 * `accept` v jedné transakci založí platební událost, případnou účetní úhradu faktury
 * ({@see InvoicePaymentService::recordPayment()}, zdroj `cash` pro hotovost, jinak
 * `manual`), alokaci a EET záznam s číslem z řady zařízení. První pokus o odeslání
 * běží až po commitu; timeout FS tak platbu nezruší, jen nechá EET čekat ve frontě.
 *
 * QR na místě má dva kroky. `createIntent` jen připraví záměr a QR data: není to
 * inkaso ani tržba. `confirmIntent` („Zákazník zadal platbu") uloží okamžik příkazu
 * a hned vytvoří EET záznam. Účetní úhrada faktury vzniká až bankovním párováním
 * (accounting_settlement_status = pending, bank_verification_status = pending):
 * obsluha potvrzuje zadání příkazu, ne připsání peněz, a zaúčtovat úhradu podle
 * pouhého kliknutí by vedlo ke dvojí úhradě, až dorazí výpis. Spárování výpisu
 * připojí důkaz ke stejné události ({@see PaymentEventReconciliation}) a druhou
 * tržbu nezaloží. Do té doby se potvrzená, nevypořádaná částka odečítá ze
 * zbývajícího dluhu faktury, aby ji nešlo přijmout podruhé.
 *
 * Klientské `eet_required` se ignoruje; rozhoduje {@see EetRecordingPolicy}.
 */
final class EetPaymentCommand
{
    private const DEFAULT_METHOD_BY_CHANNEL = [
        'cash' => 'cash',
        'card_terminal' => 'card',
        'card_online' => 'card',
        'qr_in_person' => 'bank_transfer',
        'bank_transfer' => 'bank_transfer',
        'cash_on_delivery' => 'cash_on_delivery',
        'other' => 'other',
    ];

    private const PAYABLE_STATUSES = ['issued', 'sent', 'reminded', 'paid'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordingPolicy $policy,
        private readonly EetRecordFactory $records,
        private readonly EetDispatchService $dispatch,
        private readonly EetSettingsService $settings,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly QrPaymentGenerator $qr,
        private readonly StatsRecomputer $stats,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Náhled politiky bez zápisu. Při přijetí se politika vyhodnotí znovu.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, array $input): array
    {
        $now = $this->now();
        $in = $this->normalizePayment($input, requireChannel: false);
        $receivedAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        $decision = $this->evaluate($supplierId, $in, $receivedAt, 'payment');
        $invoice = null;
        if ($in['invoice_id'] !== null) {
            $row = $this->invoice($supplierId, $in['invoice_id'], false);
            $invoice = [
                'id' => (int) $row['id'],
                'currency' => (string) $row['currency'],
                'remaining' => EetValues::fromCents($this->remainingCents($supplierId, $row)),
            ];
        }
        $requires = [];
        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            $requires[] = $in['channel'] === null || $in['channel'] === 'other' ? 'channel' : 'contact_context';
        }
        return [
            'eet' => $decision->toArray(),
            'creates_record' => $decision->createsRecord(),
            'blocks_acceptance' => $decision->blocksAcceptance(),
            'requires' => $requires,
            'amount' => $in['amount'],
            'currency' => $in['currency'],
            'invoice' => $invoice,
        ];
    }

    /**
     * Přijetí platby (hotovost, karta na terminálu, převod…). Idempotentní podle klíče.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws EetCommandException|IdempotencyConflictException
     */
    public function accept(int $supplierId, int $userId, string $idempotencyKey, array $input): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        $in = $this->normalizePayment($input, requireChannel: true);
        $hashInput = $in + ['received_at' => $input['received_at'] ?? null];
        $hash = PaymentEventService::requestHash('payments.accept', $hashInput);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            return $this->result($supplierId, $existing, true);
        }

        $now = $this->now();
        $receivedAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        $settings = $this->settings->get($supplierId);
        $decision = $this->evaluate($supplierId, $in, $receivedAt, 'payment', $settings);
        self::assertAcceptable($decision);

        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $key, $hash, $in, $receivedAt, $now, $settings, $decision): array {
                $pdo->beginTransaction();
                try {
                    $cashLike = $in['channel'] === 'cash';
                    $eventId = $this->events->insert([
                        'supplier_id' => $supplierId,
                        'idempotency_key' => $key,
                        'request_hash' => $hash,
                        'kind' => 'payment',
                        'origin' => $in['invoice_id'] !== null ? 'invoice_payment' : 'quick_sale',
                        'payment_method' => $in['payment_method'],
                        'channel' => $in['channel'],
                        'contact_context' => $in['contact_context'],
                        'business_purpose' => $in['business_purpose'],
                        'amount' => $in['amount'],
                        'currency' => $in['currency'],
                        'received_at' => EetValues::dbTime($receivedAt),
                        'recorded_at' => EetValues::dbTime($now),
                        'paid_on' => $receivedAt->format('Y-m-d'),
                        'payment_order_status' => $cashLike ? 'settled' : 'operator_confirmed',
                        'bank_verification_status' => $cashLike ? 'not_applicable' : 'pending',
                        'accounting_settlement_status' => $in['invoice_id'] !== null ? 'settled' : 'pending',
                        'invoice_id' => $in['invoice_id'],
                        'eet_decision' => $decision->decision,
                        'eet_reason_code' => $decision->reasonCode,
                        'policy_version' => EetRecordingPolicy::VERSION,
                        'note' => $in['note'],
                        'created_by' => $userId > 0 ? $userId : null,
                    ]);

                    $invoiceId = null;
                    if ($in['invoice_id'] !== null) {
                        $invoice = $this->invoice($supplierId, $in['invoice_id'], true);
                        $this->assertInvoiceAccepts($supplierId, $invoice, $in['amount'], $in['currency']);
                        $payment = $this->invoicePayments->recordPayment(
                            (int) $invoice['id'],
                            (float) $in['amount'],
                            $receivedAt->format('Y-m-d'),
                            [
                                'source' => $cashLike ? 'cash' : 'manual',
                                'note' => $in['note'] ?? 'Platba přijatá s posouzením EET',
                                'created_by' => $userId > 0 ? $userId : null,
                            ],
                        );
                        $this->events->allocate($supplierId, $eventId, $in['amount'], [
                            'invoice_id' => (int) $invoice['id'],
                            'invoice_payment_id' => (int) $payment['payment_id'],
                        ]);
                        $invoiceId = (int) $invoice['id'];
                    }

                    $recordId = null;
                    if ($decision->createsRecord()) {
                        $record = $this->records->create($supplierId, $eventId, $settings, [
                            'device_id' => $in['device_id'],
                            'sale_at' => $receivedAt,
                            'total_amount' => $in['amount'],
                            'policy_decision' => $decision->decision,
                            'policy_reason' => $decision->reasonCode,
                            'created_by' => $userId > 0 ? $userId : null,
                            'now' => $now,
                        ]);
                        $recordId = $record['id'];
                        $pdo->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
                            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
                    }

                    $pdo->commit();
                    return ['event_id' => $eventId, 'record_id' => $recordId, 'invoice_id' => $invoiceId];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            $replayed = $this->replayAfterRace($e, $supplierId, $key, $hash);
            return $this->result($supplierId, $replayed, true);
        }

        $this->afterCommit($created['invoice_id'], $created['record_id']);
        $event = $this->events->find($supplierId, $created['event_id']);
        return $this->result($supplierId, (array) $event, false);
    }

    /**
     * QR na místě: připraví platební záměr a QR data. Nevzniká úhrada ani tržba.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createIntent(int $supplierId, int $userId, ?string $idempotencyKey, array $input): array
    {
        $key = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? PaymentEventService::validateKey($idempotencyKey) : null;
        $invoiceId = self::positiveInt($input['invoice_id'] ?? null, 'invoice_id', true);
        $deviceId = self::positiveInt($input['device_id'] ?? null, 'device_id', false);
        $context = self::enumValue($input['contact_context'] ?? 'in_person', EetPolicyInput::CONTACT_CONTEXTS, 'contact_context');
        $note = self::note($input['note'] ?? null);
        $requestedAmount = isset($input['amount']) && $input['amount'] !== '' ? EetValues::amount($input['amount']) : null;
        $normalized = ['invoice_id' => $invoiceId, 'device_id' => $deviceId, 'contact_context' => $context, 'note' => $note, 'amount' => $requestedAmount];
        $hash = PaymentEventService::requestHash('payment-intents.create', $normalized);
        if ($key !== null) {
            $existing = $this->events->replay($supplierId, $key, $hash);
            if ($existing !== null) {
                return $this->intentResult($supplierId, $existing, true);
            }
        }

        $now = $this->now();
        $invoice = $this->invoice($supplierId, (int) $invoiceId, false);
        if ((string) $invoice['currency'] !== 'CZK') {
            throw EetCommandException::unsupported('QR platba na místě je podporovaná jen u faktur v CZK.', 'foreign_currency');
        }
        $remaining = $this->remainingCents($supplierId, $invoice);
        $amount = $requestedAmount ?? EetValues::fromCents($remaining);
        $this->assertInvoiceAccepts($supplierId, $invoice, $amount, 'CZK');
        if ($this->qrPayload($invoice, $amount) === null) {
            throw EetCommandException::validation('Faktura nemá bankovní účet, ze kterého by šel sestavit QR kód.', 'invoice_id');
        }
        if ($deviceId !== null) {
            $this->assertDeviceOwned($supplierId, $deviceId);
        }
        $settings = $this->settings->get($supplierId);
        $decision = $this->policy->evaluate(new EetPolicyInput($settings, $now, 'qr_in_person', $context, 'sale', 'CZK', $amount, 'payment_intent', 'intent'));
        self::assertAcceptable($decision);

        try {
            $eventId = $this->events->insert([
                'supplier_id' => $supplierId,
                'idempotency_key' => $key,
                'request_hash' => $key !== null ? $hash : null,
                'kind' => 'intent',
                'origin' => 'payment_intent',
                'payment_method' => 'bank_transfer',
                'channel' => 'qr_in_person',
                'contact_context' => $context,
                'business_purpose' => 'sale',
                'amount' => $amount,
                'currency' => 'CZK',
                'recorded_at' => EetValues::dbTime($now),
                'payment_order_status' => 'initiated',
                'bank_verification_status' => 'not_applicable',
                'accounting_settlement_status' => 'pending',
                'invoice_id' => (int) $invoice['id'],
                'device_id' => $deviceId,
                'eet_decision' => $decision->decision,
                'eet_reason_code' => $decision->reasonCode,
                'policy_version' => EetRecordingPolicy::VERSION,
                'note' => $note,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
        } catch (\PDOException $e) {
            if ($key === null) {
                throw $e;
            }
            return $this->intentResult($supplierId, $this->replayAfterRace($e, $supplierId, $key, $hash), true);
        }
        return $this->intentResult($supplierId, (array) $this->events->find($supplierId, $eventId), false);
    }

    /**
     * „Zákazník zadal platbu": okamžik příkazu, EET záznam, bez účetní úhrady.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirmIntent(int $supplierId, int $userId, string $publicId, string $idempotencyKey, array $input): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        $normalized = ['ordered_at' => $input['ordered_at'] ?? null, 'note' => self::note($input['note'] ?? null)];
        $hash = PaymentEventService::requestHash('payment-intents.confirm:' . $publicId, $normalized);
        $previous = $this->events->findByConfirmKey($supplierId, $key);
        if ($previous !== null) {
            if ((string) $previous['public_id'] !== $publicId || !hash_equals((string) $previous['confirm_request_hash'], $hash)) {
                throw new IdempotencyConflictException();
            }
            return $this->result($supplierId, $previous, true);
        }

        $now = $this->now();
        $orderedAt = EetValues::clientTime($input['ordered_at'] ?? null, $now, 'ordered_at');
        $settings = $this->settings->get($supplierId);
        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $outcome = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $publicId, $key, $hash, $orderedAt, $now, $settings, $normalized): array {
                $pdo->beginTransaction();
                try {
                    $intent = $this->events->findByPublicId($supplierId, $publicId, true);
                    if ($intent === null || (string) $intent['origin'] !== 'payment_intent') {
                        throw EetCommandException::notFound('Platební záměr nenalezen.');
                    }
                    $status = (string) $intent['payment_order_status'];
                    if ($status !== 'initiated') {
                        throw EetCommandException::stateConflict(
                            $status === 'cancelled' ? 'Platební záměr byl zrušen.' : 'Platební záměr už byl potvrzen.',
                            ['payment_order_status' => $status],
                        );
                    }
                    $decision = $this->policy->evaluate(new EetPolicyInput(
                        $settings, $orderedAt, 'qr_in_person', (string) $intent['contact_context'], 'sale',
                        (string) $intent['currency'], (string) $intent['amount'], 'payment_intent', 'payment',
                    ));
                    self::assertAcceptable($decision);

                    $pdo->prepare(
                        "UPDATE payment_events
                            SET kind = 'payment', payment_order_status = 'operator_confirmed', ordered_at = ?,
                                bank_verification_status = 'pending', accounting_settlement_status = 'pending',
                                eet_decision = ?, eet_reason_code = ?, policy_version = ?,
                                confirm_idempotency_key = ?, confirm_request_hash = ?, note = COALESCE(?, note)
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([
                        EetValues::dbTime($orderedAt), $decision->decision, $decision->reasonCode, EetRecordingPolicy::VERSION,
                        $key, $hash, $normalized['note'], (int) $intent['id'], $supplierId,
                    ]);
                    if ($intent['invoice_id'] !== null) {
                        // Zamýšlená alokace bez účetní úhrady; úhradu doplní bankovní párování.
                        $this->events->allocate($supplierId, (int) $intent['id'], (string) $intent['amount'], [
                            'invoice_id' => (int) $intent['invoice_id'],
                        ]);
                    }

                    $recordId = null;
                    if ($decision->createsRecord()) {
                        $record = $this->records->create($supplierId, (int) $intent['id'], $settings, [
                            'device_id' => $intent['device_id'] !== null ? (int) $intent['device_id'] : null,
                            'sale_at' => $orderedAt,
                            'total_amount' => (string) $intent['amount'],
                            'policy_decision' => $decision->decision,
                            'policy_reason' => $decision->reasonCode,
                            'created_by' => $userId > 0 ? $userId : null,
                            'now' => $now,
                        ]);
                        $recordId = $record['id'];
                        $pdo->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
                            ->execute([$record['device_id'], $record['unit_id'], (int) $intent['id'], $supplierId]);
                    }
                    $pdo->commit();
                    return ['event_id' => (int) $intent['id'], 'record_id' => $recordId];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (EetCommandException $e) {
            // Souběžné potvrzení se stejným klíčem doběhlo dřív: vrátit jeho výsledek.
            $raced = $this->events->findByConfirmKey($supplierId, $key);
            if ($e->httpStatus === 409 && $raced !== null && (string) $raced['public_id'] === $publicId
                && hash_equals((string) $raced['confirm_request_hash'], $hash)) {
                return $this->result($supplierId, $raced, true);
            }
            throw $e;
        } catch (\PDOException $e) {
            if (!PaymentEventService::isDuplicateKey($e)) {
                throw $e;
            }
            throw new IdempotencyConflictException();
        }

        $this->afterCommit(null, $outcome['record_id']);
        return $this->result($supplierId, (array) $this->events->find($supplierId, $outcome['event_id']), false);
    }

    /**
     * Zrušení neprovedeného záměru. Po potvrzení obsluhou už zrušit nejde: tržba
     * vznikla a neprovedený příkaz řeší záporná oprava EET (důvod failed_payment).
     *
     * @return array<string,mixed>
     */
    public function cancelIntent(int $supplierId, int $userId, string $publicId): array
    {
        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        $eventId = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $publicId): int {
            $pdo->beginTransaction();
            try {
                $intent = $this->events->findByPublicId($supplierId, $publicId, true);
                if ($intent === null || (string) $intent['origin'] !== 'payment_intent') {
                    throw EetCommandException::notFound('Platební záměr nenalezen.');
                }
                $status = (string) $intent['payment_order_status'];
                if ($status === 'initiated') {
                    $pdo->prepare(
                        "UPDATE payment_events SET payment_order_status = 'cancelled', accounting_settlement_status = 'not_applicable'
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([(int) $intent['id'], $supplierId]);
                } elseif ($status !== 'cancelled') {
                    throw EetCommandException::stateConflict(
                        'Potvrzenou platbu nelze zrušit. Pokud zákazník platbu neprovedl, vytvořte opravu EET s důvodem „neprovedená platba".',
                        ['payment_order_status' => $status, 'correction' => 'failed_payment'],
                    );
                }
                $pdo->commit();
                return (int) $intent['id'];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        return $this->result($supplierId, (array) $this->events->find($supplierId, $eventId), false);
    }

    /** @return array<string,mixed> */
    public function detail(int $supplierId, string $publicId): array
    {
        $event = $this->events->findByPublicId($supplierId, $publicId);
        if ($event === null) {
            throw EetCommandException::notFound('Platební událost nenalezena.');
        }
        return $this->result($supplierId, $event, false);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function result(int $supplierId, array $event, bool $replayed): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, (int) $event['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out = EetRecordPresenter::event($event, $record, $this->events->allocations($supplierId, (int) $event['id']));
        $out['idempotent_replay'] = $replayed;
        if ($record !== null && in_array($record['status'], ['pending', 'sending', 'retry_wait'], true)) {
            $out['poll_after_seconds'] = 5;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function intentResult(int $supplierId, array $event, bool $replayed): array
    {
        $out = $this->result($supplierId, $event, $replayed);
        if ((string) $event['payment_order_status'] === 'initiated' && $event['invoice_id'] !== null) {
            $invoice = $this->invoice($supplierId, (int) $event['invoice_id'], false);
            $payload = $this->qrPayload($invoice, (string) $event['amount']);
            $out['qr'] = $payload === null ? null : [
                'spd' => $payload,
                'image_data_uri' => $this->qr->generate('CZK', (float) $event['amount'], (string) ($invoice['varsymbol'] ?? ''),
                    self::bankSnapshot($invoice), '', null, 'Faktura ' . (string) ($invoice['varsymbol'] ?? '')),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $invoice */
    private function qrPayload(array $invoice, string $amount): ?string
    {
        return $this->qr->buildPayload('CZK', (float) $amount, (string) ($invoice['varsymbol'] ?? ''), self::bankSnapshot($invoice),
            '', null, 'Faktura ' . (string) ($invoice['varsymbol'] ?? ''));
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private static function bankSnapshot(array $invoice): array
    {
        $bank = json_decode((string) ($invoice['bank_snapshot'] ?? ''), true);
        return is_array($bank) ? $bank : [];
    }

    private function afterCommit(?int $invoiceId, ?int $recordId): void
    {
        if ($invoiceId !== null) {
            try {
                $this->stats->recomputeAfterCommit($invoiceId);
            } catch (\Throwable $e) {
                error_log('Přepočet statistik po platbě EET faktury #' . $invoiceId . ' selhal: ' . $e->getMessage());
            }
        }
        if ($recordId !== null) {
            try {
                $this->dispatch->dispatchNow($recordId);
            } catch (\Throwable $e) {
                // Záznam je uložený ve frontě; worker ho odešle. Odpověď příkazu se nemění.
                error_log('Okamžité odeslání EET záznamu #' . $recordId . ' selhalo: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string,mixed> */
    private function replayAfterRace(\PDOException $e, int $supplierId, string $key, string $hash): array
    {
        if (!PaymentEventService::isDuplicateKey($e)) {
            throw $e;
        }
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing === null) {
            throw $e;
        }
        return $existing;
    }

    /**
     * @param array{channel:?string, contact_context:string, business_purpose:string, currency:string, amount:string, invoice_id:?int} $in
     * @param array<string,mixed>|null $settings
     */
    private function evaluate(int $supplierId, array $in, \DateTimeImmutable $at, string $kind, ?array $settings = null): EetPolicyDecision
    {
        return $this->policy->evaluate(new EetPolicyInput(
            $settings ?? $this->settings->get($supplierId),
            $at,
            $in['channel'],
            $in['contact_context'],
            $in['business_purpose'],
            $in['currency'],
            $in['amount'],
            $in['invoice_id'] !== null ? 'invoice_payment' : 'quick_sale',
            $kind,
            isset($in['delegating_eic']) ? (string) $in['delegating_eic'] : null,
        ));
    }

    /** @throws EetCommandException */
    private static function assertAcceptable(EetPolicyDecision $decision): void
    {
        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            throw EetCommandException::validation($decision->messageCs, null, ['reason_code' => $decision->reasonCode]);
        }
        if ($decision->decision === EetPolicyDecision::UNSUPPORTED) {
            throw EetCommandException::unsupported($decision->messageCs, $decision->reasonCode);
        }
    }

    /**
     * Validace a normalizace vstupu platby. Otisk idempotence se počítá z výsledku.
     *
     * @param array<string,mixed> $input
     * @return array{amount:string, currency:string, channel:?string, payment_method:?string, contact_context:string,
     *               business_purpose:string, invoice_id:?int, device_id:?int, note:?string, delegating_eic:?string}
     */
    private function normalizePayment(array $input, bool $requireChannel): array
    {
        $amount = EetValues::amount($input['amount'] ?? null);
        if (EetValues::toCents($amount) <= 0) {
            throw EetCommandException::validation('Částka platby musí být kladná. Vrácení peněz zadejte jako opravu EET.', 'amount');
        }
        if (EetValues::toCents($amount) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka platby musí být pod 100 milionů.', 'amount');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw EetCommandException::validation('Neplatná měna.', 'currency');
        }
        $channel = $input['channel'] ?? null;
        if ($channel === null || $channel === '') {
            if ($requireChannel) {
                throw EetCommandException::validation('Uveďte způsob platby (channel).', 'channel');
            }
            $channel = null;
        } else {
            $channel = self::enumValue($channel, EetPolicyInput::CHANNELS, 'channel');
        }
        $method = $input['payment_method'] ?? null;
        if ($method !== null && $method !== '') {
            if (!PaymentMethods::isValid($method)) {
                throw EetCommandException::validation('Neplatný způsob úhrady.', 'payment_method');
            }
            $method = (string) $method;
        } else {
            $method = $channel !== null ? self::DEFAULT_METHOD_BY_CHANNEL[$channel] : null;
        }
        $delegating = $input['delegating_eic'] ?? null;
        return [
            'amount' => $amount,
            'currency' => $currency,
            'channel' => $channel,
            'payment_method' => $method,
            'contact_context' => self::enumValue($input['contact_context'] ?? 'unknown', EetPolicyInput::CONTACT_CONTEXTS, 'contact_context'),
            'business_purpose' => self::enumValue($input['business_purpose'] ?? 'sale', EetPolicyInput::BUSINESS_PURPOSES, 'business_purpose'),
            'invoice_id' => self::positiveInt($input['invoice_id'] ?? null, 'invoice_id', false),
            'device_id' => self::positiveInt($input['device_id'] ?? null, 'device_id', false),
            'note' => self::note($input['note'] ?? null),
            'delegating_eic' => is_string($delegating) && trim($delegating) !== '' ? trim($delegating) : null,
        ];
    }

    /**
     * Faktura firmy (volitelně zamčená). Cizí nebo neexistující = validační chyba.
     *
     * @return array<string,mixed>
     */
    private function invoice(int $supplierId, int $invoiceId, bool $forUpdate): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.invoice_type, i.status, i.amount_to_pay, i.paid_total, i.varsymbol, i.bank_snapshot,
                    cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::validation('Faktura nenalezena.', 'invoice_id');
        }
        return $row;
    }

    /** @param array<string,mixed> $invoice */
    private function assertInvoiceAccepts(int $supplierId, array $invoice, string $amount, string $currency): void
    {
        if (!in_array((string) $invoice['invoice_type'], ['invoice', 'proforma'], true)) {
            throw EetCommandException::validation('Platbu lze přijmout jen k faktuře nebo zálohové faktuře.', 'invoice_id');
        }
        if (!in_array((string) $invoice['status'], self::PAYABLE_STATUSES, true)) {
            throw EetCommandException::validation('Platbu lze přijmout jen k vystavenému dokladu.', 'invoice_id');
        }
        if ((string) $invoice['currency'] !== $currency) {
            throw EetCommandException::validation('Měna platby se liší od měny faktury.', 'currency');
        }
        $remaining = $this->remainingCents($supplierId, $invoice);
        $tolerance = (int) round(InvoicePaymentService::TOLERANCE * 100);
        if (EetValues::toCents($amount) > $remaining + $tolerance) {
            throw EetCommandException::validation(
                'Částka převyšuje zbývající dluh faktury (' . EetValues::fromCents(max(0, $remaining)) . ').',
                'amount',
                ['remaining' => EetValues::fromCents(max(0, $remaining))],
            );
        }
    }

    /**
     * Zbývá k úhradě: částka k úhradě − zaevidované úhrady − potvrzené QR příkazy,
     * které ještě nevypořádalo bankovní párování.
     *
     * @param array<string,mixed> $invoice
     */
    private function remainingCents(int $supplierId, array $invoice): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(a.amount), 0)
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.invoice_id = ?
                AND a.invoice_payment_id IS NULL AND a.bank_transaction_id IS NULL
                AND e.payment_order_status = 'operator_confirmed' AND e.accounting_settlement_status = 'pending'"
        );
        $stmt->execute([$supplierId, (int) $invoice['id']]);
        $pending = EetValues::toCents(EetValues::amount((string) $stmt->fetchColumn()));
        return EetValues::toCents(EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0')))
            - EetValues::toCents(EetValues::amount((string) $invoice['paid_total']))
            - $pending;
    }

    private function assertDeviceOwned(int $supplierId, int $deviceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'device_id');
        }
    }

    private function assertNoOpenTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('Platební příkaz EET řídí vlastní transakci a odesílá až po commitu.');
        }
    }

    private function now(): \DateTimeImmutable
    {
        return EetValues::local($this->clock->now());
    }

    /** @param list<string> $allowed */
    private static function enumValue(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw EetCommandException::validation('Neplatná hodnota pole ' . $field . '.', $field, ['allowed' => $allowed]);
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $field, bool $required): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw EetCommandException::validation('Chybí pole ' . $field . '.', $field);
            }
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw EetCommandException::validation('Neplatné pole ' . $field . '.', $field);
        }
        return $value;
    }

    private static function note(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
