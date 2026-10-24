<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Evidence tržeb z pokladny (EET-05): příjmový pokladní doklad účelu `sale`
 * a `invoice_payment`.
 *
 * Tržba vzniká při zaúčtování dokladu, v jeho transakci: platební událost (hotovost,
 * osobní kontakt), alokace na pokladní doklad i na úhradu faktury, kterou zaúčtování
 * právě založilo, a EET záznam s číslem z řady zařízení. Úhrada faktury z PPD tak
 * nese stejnou identitu platby a druhá tržba z cesty úhrady nevznikne. Odeslání
 * běží až po commitu ({@see EetDispatchService::dispatchAfterCommit()}).
 *
 * Doklad se eviduje u firmy, která k okamžiku tržby ({@see saleTime()}) eviduje.
 * Zařízení: výslovně zvolené u dokladu, jinak namapované na pokladnu
 * (`eet_devices.cash_register_id`), jinak výchozí zařízení firmy. Povinnou hotovost
 * bez zařízení doklad nezaúčtuje (422 `eet_device_required`), tiše se neobchází.
 * Převod mezi pokladnami, vklad, výdaj ani jiný pohyb tržbou nejsou (příjmový
 * doklad sám nic nerozhoduje). Povinná tržba, kterou MVP neumí (cizí měna), doklad
 * nezaúčtuje: politika se nesmí tiše obejít.
 *
 * Storno dokladu s tržbou je skutečné vrácení peněz z pokladny, proto vytvoří
 * zápornou opravu celé dosud neopravené části (výchozí důvod `refund`, nebo
 * `wrong_data` / `duplicate` u chybně pořízeného dokladu). Oprava je vázaná na
 * storno idempotentním klíčem dokladu a účetně ji vypořádává samotné storno.
 */
final class EetCashDocumentRecorder
{
    public const SUBJECT_PURPOSES = ['sale', 'invoice_payment'];
    public const REVERSAL_REASONS = ['refund', 'wrong_data', 'duplicate'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordingPolicy $policy,
        private readonly EetRecordFactory $records,
        private readonly EetSettingsService $settings,
        private readonly EetCorrectionService $corrections,
        private readonly EetDispatchService $dispatch,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Po zaúčtování dokladu (v jeho transakci). Vrací id EET záznamu, nebo null.
     *
     * @param array<string,mixed> $doc řádek cash_documents (stav před označením posted)
     * @throws EetCommandException
     */
    public function afterPost(int $supplierId, array $doc, ?int $invoicePaymentId, ?int $explicitDeviceId, ?int $userId): ?int
    {
        if (($doc['doc_type'] ?? null) !== 'in' || !in_array((string) ($doc['purpose'] ?? ''), self::SUBJECT_PURPOSES, true)) {
            return null;
        }
        $cashDocumentId = (int) $doc['id'];
        $invoiceId = ($doc['invoice_id'] ?? null) !== null ? (int) $doc['invoice_id'] : null;
        if ($invoiceId !== null) {
            $this->assertNoQrOverlap($supplierId, $invoiceId, $doc);
        }
        $now = EetValues::local($this->clock->now());
        $saleAt = self::saleTime($doc, $now);
        $settings = $this->settings->get($supplierId);
        if (!$this->policy->activeAt($settings, $saleAt)) {
            return null;
        }
        // Tržbu dokladu pozná alokace na doklad, ne klíč: klíč by šel obsadit zvenčí.
        if ($this->saleRecordId($supplierId, $cashDocumentId) !== null) {
            return null;
        }
        $key = PaymentEventService::internalKey('cash-document', $cashDocumentId);
        $invoiceType = $invoiceId !== null ? $this->invoiceType($supplierId, $invoiceId) : null;
        $purpose = $invoiceType === 'proforma' ? 'order_deposit' : 'sale';
        $currency = strtoupper((string) ($doc['currency_code'] ?? 'CZK'));
        $amount = EetValues::amount($currency === 'CZK'
            ? (float) $doc['total_amount']
            : (float) ($doc['amount_foreign'] ?? $doc['total_amount']));
        $origin = $invoiceId !== null ? 'invoice_payment' : 'cash_document';

        $decision = $this->policy->evaluate(new EetPolicyInput(
            $settings, $saleAt, 'cash', 'in_person', $purpose, $currency, $amount, $origin, 'payment',
        ));
        if ($decision->decision === EetPolicyDecision::UNSUPPORTED) {
            throw EetCommandException::unsupported($decision->messageCs, $decision->reasonCode);
        }
        if (!$decision->createsRecord()) {
            return null;
        }
        $deviceId = $explicitDeviceId !== null && $explicitDeviceId > 0
            ? $this->ownedDevice($supplierId, $explicitDeviceId)
            : ($this->deviceForRegister($supplierId, (int) $doc['register_id']) ?? $this->defaultDevice($supplierId, $settings));
        if ($deviceId === null) {
            throw new EetCommandException('eet_device_required',
                'Firma eviduje tržby (EET) a hotovostní příjem se musí evidovat, ale pokladna nemá přiřazené pokladní zařízení '
                . 'a firma nemá výchozí aktivní zařízení. V Nastavení EET přiřaďte pokladně zařízení, nebo zvolte zařízení u dokladu.',
                422, ['field' => 'eet_device_id']);
        }

        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => PaymentEventService::requestHash('cash-documents.post', ['cash_document_id' => $cashDocumentId]),
            'kind' => 'payment',
            'origin' => 'cash_document',
            'payment_method' => 'cash',
            'channel' => 'cash',
            'contact_context' => 'in_person',
            'business_purpose' => $purpose,
            'amount' => $amount,
            'currency' => $currency,
            'received_at' => EetValues::dbTime($saleAt),
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => (string) $doc['issue_date'],
            'payment_order_status' => 'settled',
            'bank_verification_status' => 'not_applicable',
            'accounting_settlement_status' => 'settled',
            'invoice_id' => $invoiceId,
            'eet_decision' => $decision->decision,
            'eet_reason_code' => $decision->reasonCode,
            'policy_version' => EetRecordingPolicy::VERSION,
            'note' => isset($doc['doc_number']) ? mb_substr((string) $doc['doc_number'], 0, 255) : null,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
        ]);
        $this->events->allocate($supplierId, $eventId, $amount, [
            'invoice_id' => $invoiceId,
            'invoice_payment_id' => $invoicePaymentId,
            'cash_document_id' => $cashDocumentId,
        ]);
        $record = $this->records->create($supplierId, $eventId, $settings, [
            'device_id' => $deviceId,
            'sale_at' => $saleAt,
            'total_amount' => $amount,
            'policy_decision' => $decision->decision,
            'policy_reason' => $decision->reasonCode,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
            'now' => $now,
        ]);
        $this->db->pdo()->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
        $this->dispatch->dispatchAfterCommit($record['id']);
        return $record['id'];
    }

    /**
     * Při stornu dokladu (v jeho transakci), PŘED zrušením navázané úhrady faktury.
     * Vrací id opravy, nebo null (doklad bez tržby, tržba už opravená celá).
     *
     * @throws EetCommandException
     */
    public function afterReverse(int $supplierId, int $cashDocumentId, ?string $reason, ?int $userId, ?string $note = null, ?int $deviceId = null): ?int
    {
        if ($deviceId !== null && $deviceId > 0) {
            $deviceId = $this->ownedDevice($supplierId, $deviceId);
        }
        $recordId = $this->saleRecordId($supplierId, $cashDocumentId);
        if ($recordId === null) {
            return null;
        }
        $reason = $reason === null || $reason === '' ? 'refund' : $reason;
        if (!in_array($reason, self::REVERSAL_REASONS, true)) {
            throw EetCommandException::validation('Neplatný důvod opravy EET při stornu.', 'eet_correction_reason',
                ['allowed' => self::REVERSAL_REASONS]);
        }
        $created = $this->corrections->createWithinTransaction(
            $supplierId, $userId, $recordId, PaymentEventService::internalKey('cash-reversal', $cashDocumentId), $reason,
            $note !== null ? mb_substr($note, 0, 255) : null, $deviceId !== null && $deviceId > 0 ? $deviceId : null,
        );
        if ($created === null) {
            return null;
        }
        // Vrácení peněz účtuje samo storno dokladu: oprava je vypořádaná a vázaná na doklad.
        $this->db->pdo()->prepare(
            "UPDATE payment_events SET accounting_settlement_status = IF(accounting_settlement_status = 'pending', 'settled', accounting_settlement_status)
              WHERE id = ? AND supplier_id = ?"
        )->execute([$created['event_id'], $supplierId]);
        $exists = $this->db->pdo()->prepare('SELECT 1 FROM payment_event_allocations WHERE supplier_id = ? AND payment_event_id = ? AND cash_document_id = ?');
        $exists->execute([$supplierId, $created['event_id'], $cashDocumentId]);
        if ($exists->fetchColumn() === false) {
            $amount = $this->db->pdo()->prepare('SELECT amount FROM payment_events WHERE id = ? AND supplier_id = ?');
            $amount->execute([$created['event_id'], $supplierId]);
            $this->events->allocate($supplierId, $created['event_id'], (string) $amount->fetchColumn(), ['cash_document_id' => $cashDocumentId]);
        }
        return $created['record_id'];
    }

    /** Má doklad evidovanou tržbu (záznam kind = sale)? */
    public function hasSale(int $supplierId, int $cashDocumentId): bool
    {
        return $this->saleRecordId($supplierId, $cashDocumentId) !== null;
    }

    private function saleRecordId(int $supplierId, int $cashDocumentId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id AND e.kind = 'payment'
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'sale'
              WHERE a.supplier_id = ? AND a.cash_document_id = ?
              ORDER BY r.id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $cashDocumentId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Okamžik tržby z dokladu, ne čas zaúčtování: doklad vystavený dnes → teď; jinak čas
     * pořízení dokladu, pokud padá do dne vystavení; jinak poledne dne vystavení.
     * Nikdy ne v budoucnosti. Pozdní evidence se neodmítá, záznam dostane lhůtu od
     * času tržby a příznak po lhůtě ({@see EetRecordFactory}).
     *
     * @param array<string,mixed> $doc
     */
    public static function saleTime(array $doc, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $now = EetValues::local($now);
        $issueDate = substr((string) ($doc['issue_date'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $issueDate) !== 1 || $issueDate === $now->format('Y-m-d')) {
            return $now;
        }
        $created = $doc['created_at'] ?? null;
        if (is_string($created) && $created !== '') {
            try {
                $createdAt = new \DateTimeImmutable($created, EetValues::zone());
                if ($createdAt->format('Y-m-d') === $issueDate) {
                    return min($createdAt, $now);
                }
            } catch (\Exception) {
            }
        }
        return min(new \DateTimeImmutable($issueDate . ' 12:00:00', EetValues::zone()), $now);
    }

    /**
     * Úhrada faktury z PPD nesmí krýt dluh, který drží potvrzený QR příkaz čekající na banku
     * (jinak dvě tržby na jedny peníze). Úhrada je v té chvíli zapsaná a faktura zamčená.
     *
     * @param array<string,mixed> $doc
     * @throws EetCommandException
     */
    private function assertNoQrOverlap(int $supplierId, int $invoiceId, array $doc): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT amount_to_pay, paid_total FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            return;
        }
        $docCents = EetValues::toCents(EetValues::amount((float) ($doc['total_amount'] ?? 0)));
        $paidAfter = EetValues::toCents(EetValues::amount((string) ($invoice['paid_total'] ?? '0')));
        $this->events->assertNoQrOverlap($supplierId, $invoiceId,
            EetValues::toCents(EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0'))),
            $paidAfter - $docCents, $docCents,
            (int) round(\MyInvoice\Service\Invoice\InvoicePaymentService::TOLERANCE * 100));
    }

    /** @param array<string,mixed> $settings */
    private function defaultDevice(int $supplierId, array $settings): ?int
    {
        $deviceId = (int) ($settings['default_device_id'] ?? 0);
        if ($deviceId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ? AND active = 1');
        $stmt->execute([$deviceId, $supplierId]);
        return $stmt->fetchColumn() === false ? null : $deviceId;
    }

    private function deviceForRegister(int $supplierId, int $registerId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM eet_devices WHERE supplier_id = ? AND cash_register_id = ? AND active = 1 ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $registerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @throws EetCommandException */
    private function ownedDevice(int $supplierId, int $deviceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'eet_device_id');
        }
        return $deviceId;
    }

    private function invoiceType(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string) $type;
    }
}
