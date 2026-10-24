<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtení evidence EET: seznam s keyset stránkováním, detail s pokusy, export
 * po dávkách a souhrn faktury. Každý dotaz začíná omezením na firmu a jde po
 * složeném indexu (supplier_id, [status|unit_id|device_id,] sale_at, id).
 */
final class EetRecordQuery
{
    public const MAX_LIMIT = 100;
    public const EXPORT_BATCH = 500;
    private const STATUSES = ['pending', 'sending', 'retry_wait', 'confirmed', 'confirmed_warning', 'action_required', 'verification_ok'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $filters from, to (YYYY-MM-DD, včetně), status, unit_id, device_id, kind
     * @return array{items: list<array<string,mixed>>, next_cursor: ?string}
     */
    public function list(int $supplierId, array $filters, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$where, $params] = $this->where($supplierId, $filters);
        if ($cursor !== null && $cursor !== '') {
            [$saleAt, $id] = self::decodeCursor($cursor);
            $where[] = '(sale_at < ? OR (sale_at = ? AND id < ?))';
            array_push($params, $saleAt, $saleAt, $id);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM eet_records WHERE ' . implode(' AND ', $where)
            . ' ORDER BY sale_at DESC, id DESC LIMIT ' . ($limit + 1)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $lastRow = $rows[count($rows) - 1];
            $next = self::encodeCursor((string) $lastRow['sale_at'], (int) $lastRow['id']);
        }
        return ['items' => array_map([EetRecordPresenter::class, 'record'], $rows), 'next_cursor' => $next];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $recordId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$recordId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Detail s pokusy a opravami. XML pokusů jen s `$includeXml` (právo eet.correct
     * v přihlášené relaci); bearer token ho nedostane nikdy.
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $supplierId, int $recordId, bool $includeXml): ?array
    {
        $row = $this->find($supplierId, $recordId);
        if ($row === null) {
            return null;
        }
        $pdo = $this->db->pdo();
        $columns = 'attempt_no, uuid_zpravy, sent_at, first_attempt, verification, certificate_id, outcome, http_status,
                    error_code, error_message, pok, test_flag, signature_verified, duration_ms, request_sha256, received_at'
            . ($includeXml ? ', request_xml, response_xml' : '');
        $attempts = $pdo->prepare("SELECT {$columns} FROM eet_attempts WHERE supplier_id = ? AND record_id = ? ORDER BY attempt_no");
        $attempts->execute([$supplierId, $recordId]);
        $corrections = $pdo->prepare("SELECT * FROM eet_records WHERE supplier_id = ? AND corrects_record_id = ? AND kind = 'correction' ORDER BY id");
        $corrections->execute([$supplierId, $recordId]);
        $event = null;
        if ($row['payment_event_id'] !== null) {
            $e = $pdo->prepare('SELECT * FROM payment_events WHERE id = ? AND supplier_id = ?');
            $e->execute([(int) $row['payment_event_id'], $supplierId]);
            $event = $e->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        return EetRecordPresenter::record($row) + [
            'payment_event' => $event !== null ? EetRecordPresenter::event($event, null) : null,
            'corrections' => array_map([EetRecordPresenter::class, 'record'], $corrections->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'attempts' => array_map(static function (array $a) use ($includeXml): array {
                $out = [
                    'attempt_no' => (int) $a['attempt_no'],
                    'uuid_zpravy' => (string) $a['uuid_zpravy'],
                    'sent_at' => EetValues::iso((string) $a['sent_at']),
                    'first_attempt' => (int) $a['first_attempt'] === 1,
                    'verification' => (int) $a['verification'] === 1,
                    'certificate_id' => $a['certificate_id'] !== null ? (int) $a['certificate_id'] : null,
                    'outcome' => (string) $a['outcome'],
                    'http_status' => $a['http_status'] !== null ? (int) $a['http_status'] : null,
                    'error_code' => $a['error_code'] !== null ? (int) $a['error_code'] : null,
                    'error_message' => $a['error_message'],
                    'pok' => $a['pok'],
                    'test_flag' => $a['test_flag'] !== null ? (int) $a['test_flag'] === 1 : null,
                    'signature_verified' => (int) $a['signature_verified'] === 1,
                    'duration_ms' => $a['duration_ms'] !== null ? (int) $a['duration_ms'] : null,
                    'request_sha256' => $a['request_sha256'],
                    'received_at' => $a['received_at'],
                ];
                if ($includeXml) {
                    $out['request_xml'] = $a['request_xml'];
                    $out['response_xml'] = $a['response_xml'];
                }
                return $out;
            }, $attempts->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
    }

    /**
     * Řádky exportu po dávkách (keyset vzestupně), bez načtení celého období do paměti.
     *
     * @param array<string,mixed> $filters
     * @return \Generator<int, array<string,mixed>>
     */
    public function exportRows(int $supplierId, array $filters): \Generator
    {
        [$baseWhere, $baseParams] = $this->where($supplierId, $filters);
        $lastSaleAt = null;
        $lastId = 0;
        do {
            $where = $baseWhere;
            $params = $baseParams;
            if ($lastSaleAt !== null) {
                $where[] = '(sale_at > ? OR (sale_at = ? AND id > ?))';
                array_push($params, $lastSaleAt, $lastSaleAt, $lastId);
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, public_id, kind, corrects_record_id, correction_reason, environment, eic, unit_number,
                        device_code, sequence_number, sale_at, sale_at_offset, total_amount, status, pok, confirmed_at,
                        overdue, deadline_at, policy_reason
                   FROM eet_records WHERE ' . implode(' AND ', $where)
                . ' ORDER BY sale_at, id LIMIT ' . self::EXPORT_BATCH
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                yield $row;
            }
            if ($rows !== []) {
                $last = $rows[count($rows) - 1];
                $lastSaleAt = (string) $last['sale_at'];
                $lastId = (int) $last['id'];
            }
        } while (count($rows) === self::EXPORT_BATCH);
    }

    /**
     * EET souhrn faktury: platební události s jejich záznamy a opravami.
     *
     * @return list<array<string,mixed>>
     */
    public function forInvoice(int $supplierId, int $invoiceId): array
    {
        $pdo = $this->db->pdo();
        $events = $pdo->prepare('SELECT * FROM payment_events WHERE supplier_id = ? AND invoice_id = ? ORDER BY recorded_at, id');
        $events->execute([$supplierId, $invoiceId]);
        $rows = $events->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $records = $pdo->prepare("SELECT * FROM eet_records WHERE supplier_id = ? AND payment_event_id IN ({$in})");
        $records->execute([$supplierId, ...$ids]);
        $byEvent = [];
        foreach ($records->fetchAll(PDO::FETCH_ASSOC) ?: [] as $record) {
            $byEvent[(int) $record['payment_event_id']] = $record;
        }
        $allocations = $pdo->prepare(
            "SELECT payment_event_id, invoice_id, invoice_payment_id, cash_document_id, bank_transaction_id, amount
               FROM payment_event_allocations WHERE supplier_id = ? AND payment_event_id IN ({$in}) ORDER BY id"
        );
        $allocations->execute([$supplierId, ...$ids]);
        $allocByEvent = [];
        foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
            $allocByEvent[(int) $a['payment_event_id']][] = $a;
        }
        return array_map(static function (array $event) use ($byEvent, $allocByEvent): array {
            $record = $byEvent[(int) $event['id']] ?? null;
            $out = EetRecordPresenter::event($event, $record, $allocByEvent[(int) $event['id']] ?? []);
            $out['eet_record'] = $record !== null ? EetRecordPresenter::record($record) : null;
            return $out;
        }, $rows);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function where(int $supplierId, array $filters): array
    {
        $where = ['supplier_id = ?'];
        $params = [$supplierId];
        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw EetCommandException::validation('Neplatný stav.', 'status');
            }
            $where[] = 'status = ?';
            $params[] = $status;
        }
        foreach (['unit_id', 'device_id'] as $column) {
            $value = $filters[$column] ?? null;
            if ($value !== null && $value !== '') {
                if (!ctype_digit((string) $value)) {
                    throw EetCommandException::validation('Neplatné pole ' . $column . '.', $column);
                }
                $where[] = $column . ' = ?';
                $params[] = (int) $value;
            }
        }
        $kind = $filters['kind'] ?? null;
        if ($kind !== null && $kind !== '') {
            if (!in_array($kind, ['sale', 'correction'], true)) {
                throw EetCommandException::validation('Neplatný druh záznamu.', 'kind');
            }
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw EetCommandException::validation('Datum musí být ve formátu RRRR-MM-DD.', $key);
            }
            $bound = $key === 'to' ? (new \DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d') : $value;
            $where[] = 'sale_at ' . $op . ' ?';
            $params[] = $bound . ' 00:00:00';
        }
        return [$where, $params];
    }

    private static function encodeCursor(string $saleAt, int $id): string
    {
        return rtrim(strtr(base64_encode($saleAt . '|' . $id), '+/', '-_'), '=');
    }

    /** @return array{0:string, 1:int} */
    private static function decodeCursor(string $cursor): array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/D', $raw, $m) !== 1) {
            throw EetCommandException::validation('Neplatný kurzor stránkování.', 'cursor');
        }
        return [$m[1], (int) $m[2]];
    }
}
