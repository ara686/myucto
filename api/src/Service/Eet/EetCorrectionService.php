<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Záporná oprava evidované tržby (vratka, neprovedený QR příkaz, chybná data, duplicita).
 *
 * EET neumí původní zprávu zrušit ani přepsat. Oprava je nová záporná tržba se
 * svým číslem z řady zařízení, aktuálním časem a novým UUID; vazbu na původní
 * záznam a důvod drží MyÚčto interně. Každá oprava má vlastní platební událost
 * (kind = refund), takže UNIQUE(payment_event_id, kind) platí i pro opravy.
 *
 * Součet oprav nesmí překročit dosud neopravenou část původní tržby. Souběžné
 * opravy zamykají původní záznam (FOR UPDATE), opakované odeslání stejného
 * `Idempotency-Key` vrátí původní opravu.
 *
 * Oprava jde do stejného prostředí a pod stejné EIČ jako původní záznam bez ohledu
 * na pozdější změnu nastavení.
 *
 * Účetní dopad: oprava je jen fiskální záznam a platební událost. Skutečné vrácení
 * peněz (důvod `refund`) se dál účtuje existujícími cestami (dobropis, výdajový
 * pokladní doklad, odchozí platba); událost proto nese
 * `accounting_settlement_status = pending`. Ostatní důvody peníze nehýbou
 * (`not_applicable`) a fiktivní pokladní ani bankovní výdej nevzniká.
 */
final class EetCorrectionService
{
    public const REASONS = ['refund', 'failed_payment', 'wrong_data', 'duplicate', 'other'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordFactory $records,
        private readonly EetDispatchService $dispatch,
        private readonly EetSettingsService $settings,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, int $recordId, array $input): array
    {
        $original = $this->original($supplierId, $recordId, false);
        $remaining = $this->remainingCents($supplierId, $original);
        $amount = isset($input['amount']) && $input['amount'] !== '' ? EetValues::amount($input['amount']) : EetValues::fromCents($remaining);
        $reason = isset($input['reason']) && $input['reason'] !== '' ? self::reason($input['reason']) : null;
        $cents = EetValues::toCents($amount);
        return [
            'original' => EetRecordPresenter::record($original),
            'corrected_total' => EetValues::fromCents(EetValues::toCents((string) $original['total_amount']) - $remaining),
            'remaining' => EetValues::fromCents($remaining),
            'proposed' => [
                'total_amount' => EetValues::fromCents(-$cents),
                'reason' => $reason,
                'device_id' => (int) ($input['device_id'] ?? 0) ?: (int) $original['device_id'],
                'environment' => (string) $original['environment'],
                'sale_at' => EetValues::local($this->clock->now())->format(DATE_ATOM),
            ],
            'allowed' => $cents > 0 && $cents <= $remaining,
            'accounting' => $reason === 'refund'
                ? 'Vrácení peněz zaúčtujte dobropisem, výdajovým pokladním dokladem nebo odchozí platbou.'
                : 'Oprava nemění pohyb peněz ani účetnictví.',
        ];
    }

    /**
     * @param array<string,mixed> $input {amount, reason, note?, device_id?}
     * @return array<string,mixed>
     */
    public function create(int $supplierId, int $userId, int $recordId, string $idempotencyKey, array $input): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        $amount = EetValues::amount($input['amount'] ?? null);
        $cents = EetValues::toCents($amount);
        if ($cents <= 0) {
            throw EetCommandException::validation('Částka opravy se zadává kladně (o kolik se tržba snižuje).', 'amount');
        }
        $reason = self::reason($input['reason'] ?? null);
        $note = isset($input['note']) && is_string($input['note']) && trim($input['note']) !== '' ? mb_substr(trim($input['note']), 0, 255) : null;
        $deviceId = isset($input['device_id']) && (int) $input['device_id'] > 0 ? (int) $input['device_id'] : null;
        $hash = PaymentEventService::requestHash('records.corrections:' . $recordId,
            ['amount' => $amount, 'reason' => $reason, 'note' => $note, 'device_id' => $deviceId]);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            return $this->result($supplierId, $existing, true);
        }

        $now = EetValues::local($this->clock->now());
        $settings = $this->settings->get($supplierId);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Oprava EET řídí vlastní transakci a odesílá až po commitu.');
        }
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $recordId, $key, $hash, $cents, $reason, $note, $deviceId, $now, $settings): array {
                $pdo->beginTransaction();
                try {
                    $original = $this->original($supplierId, $recordId, true);
                    $created = $this->insertLocked($supplierId, $userId, $original, $key, $hash, $cents, $reason, $note, $deviceId, $now, $settings);
                    $pdo->commit();
                    return $created;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            if (!PaymentEventService::isDuplicateKey($e)) {
                throw $e;
            }
            $existing = $this->events->replay($supplierId, $key, $hash);
            if ($existing === null) {
                throw $e;
            }
            return $this->result($supplierId, $existing, true);
        }

        try {
            $this->dispatch->dispatchNow($created['record_id']);
        } catch (\Throwable $e) {
            error_log('Okamžité odeslání opravy EET #' . $created['record_id'] . ' selhalo: ' . $e->getMessage());
        }
        return $this->result($supplierId, (array) $this->events->find($supplierId, $created['event_id']), false);
    }

    /**
     * Oprava v transakci volajícího (storno pokladního dokladu): celá dosud neopravená
     * část tržby, odeslání až po commitu. Idempotentní podle klíče; když už je tržba
     * opravená celá, nevznikne nic a vrací null.
     *
     * @return array{event_id:int, record_id:int}|null
     */
    public function createWithinTransaction(int $supplierId, ?int $userId, int $recordId, string $idempotencyKey, string $reason, ?string $note = null): ?array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Oprava EET v transakci volajícího vyžaduje otevřenou transakci.');
        }
        $key = PaymentEventService::validateKey($idempotencyKey);
        $reason = self::reason($reason);
        $hash = PaymentEventService::requestHash('records.corrections:' . $recordId, ['reason' => $reason, 'scope' => 'remaining']);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            $stmt = $pdo->prepare("SELECT id FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? AND kind = 'correction'");
            $stmt->execute([$supplierId, (int) $existing['id']]);
            return ['event_id' => (int) $existing['id'], 'record_id' => (int) $stmt->fetchColumn()];
        }
        $original = $this->original($supplierId, $recordId, true);
        $remaining = $this->remainingCents($supplierId, $original);
        if ($remaining <= 0) {
            return null;
        }
        $created = $this->insertLocked(
            $supplierId, $userId ?? 0, $original, $key, $hash, $remaining, $reason, $note, null,
            EetValues::local($this->clock->now()), $this->settings->get($supplierId),
        );
        $this->dispatch->dispatchAfterCommit($created['record_id']);
        return $created;
    }

    /**
     * Zápis opravy nad zamčeným původním záznamem.
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $settings
     * @return array{event_id:int, record_id:int}
     */
    private function insertLocked(int $supplierId, int $userId, array $original, string $key, string $hash, int $cents, string $reason,
        ?string $note, ?int $deviceId, \DateTimeImmutable $now, array $settings): array
    {
        $remaining = $this->remainingCents($supplierId, $original);
        if ($cents > $remaining) {
            throw EetCommandException::validation(
                'Oprava překračuje dosud neopravenou část tržby (' . EetValues::fromCents($remaining) . ').',
                'amount',
                ['remaining' => EetValues::fromCents($remaining)],
            );
        }
        $originalEvent = $original['payment_event_id'] !== null
            ? $this->events->find($supplierId, (int) $original['payment_event_id'])
            : null;

        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'kind' => 'refund',
            'origin' => 'correction',
            'payment_method' => $originalEvent['payment_method'] ?? null,
            'channel' => $originalEvent['channel'] ?? null,
            'contact_context' => $originalEvent['contact_context'] ?? 'unknown',
            'business_purpose' => $originalEvent['business_purpose'] ?? 'sale',
            'amount' => EetValues::fromCents(-$cents),
            'currency' => 'CZK',
            'received_at' => $reason === 'refund' ? EetValues::dbTime($now) : null,
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => $reason === 'refund' ? $now->format('Y-m-d') : null,
            'payment_order_status' => match ($reason) {
                'refund' => 'settled',
                'failed_payment' => 'failed',
                default => 'cancelled',
            },
            'bank_verification_status' => 'not_applicable',
            'accounting_settlement_status' => $reason === 'refund' ? 'pending' : 'not_applicable',
            'invoice_id' => $originalEvent['invoice_id'] ?? null,
            'eet_decision' => EetPolicyDecision::REQUIRED,
            'eet_reason_code' => 'correction_' . $reason,
            'policy_version' => EetRecordingPolicy::VERSION,
            'corrects_event_id' => $originalEvent !== null ? (int) $originalEvent['id'] : null,
            'note' => $note,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        $record = $this->records->create($supplierId, $eventId, [
            'eic' => (string) $original['eic'],
            'environment' => (string) $original['environment'],
        ] + $settings, [
            'device_id' => $deviceId ?? (int) $original['device_id'],
            'sale_at' => $now,
            'total_amount' => EetValues::fromCents(-$cents),
            'kind' => 'correction',
            'corrects_record_id' => (int) $original['id'],
            'correction_reason' => $reason,
            'policy_decision' => EetPolicyDecision::REQUIRED,
            'policy_reason' => 'correction_' . $reason,
            'created_by' => $userId > 0 ? $userId : null,
            'now' => $now,
        ]);
        $this->db->pdo()->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
        return ['event_id' => $eventId, 'record_id' => $record['id']];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function result(int $supplierId, array $event, bool $replayed): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? AND kind = 'correction'");
        $stmt->execute([$supplierId, (int) $event['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out = EetRecordPresenter::event($event, $record);
        $out['correction'] = $record !== null ? EetRecordPresenter::record($record) : null;
        if ($record !== null && $record['corrects_record_id'] !== null) {
            $original = $this->original($supplierId, (int) $record['corrects_record_id'], false);
            $out['original_remaining'] = EetValues::fromCents($this->remainingCents($supplierId, $original));
        }
        $out['idempotent_replay'] = $replayed;
        return $out;
    }

    /** @return array<string,mixed> */
    private function original(int $supplierId, int $recordId, bool $forUpdate): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE id = ? AND supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$recordId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::notFound('Záznam EET nenalezen.');
        }
        if ((string) $row['kind'] !== 'sale') {
            throw EetCommandException::validation('Opravit lze jen původní tržbu, ne opravu ani diagnostiku.', 'record_id');
        }
        return $row;
    }

    /** @param array<string,mixed> $original */
    private function remainingCents(int $supplierId, array $original): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM eet_records
              WHERE supplier_id = ? AND corrects_record_id = ? AND kind = 'correction'"
        );
        $stmt->execute([$supplierId, (int) $original['id']]);
        $corrected = EetValues::toCents(EetValues::amount((string) $stmt->fetchColumn()));
        return EetValues::toCents((string) $original['total_amount']) + $corrected;
    }

    private static function reason(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::REASONS, true)) {
            throw EetCommandException::validation('Uveďte důvod opravy.', 'reason', ['allowed' => self::REASONS]);
        }
        return $value;
    }
}
