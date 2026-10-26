<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;

/**
 * Čerpání zálohy konečnou fakturou po dvojicích záloha × faktura: poskytnuté zálohy na 314
 * (`purchase_advance_deductions`, migrace 2190) a přijaté zálohy na 324
 * (`invoice_advance_deductions`, migrace 2195). Saldokonto čerpání čte v
 * {@see SaldoRepository} (fetchPaidAdvances, fetchOpeningDocuments, fetchInvoicePrepayments,
 * fetchReceivedAdvances), platnost od zaúčtování konečné faktury drží
 * {@see SaldoRepository::advanceDeductionCountsSql()}.
 *
 * Konečná faktura čerpá zálohu stranou zálohového účtu, která zálohu snižuje (přijatá
 * faktura dal 314, vydaná má dáti 324). Část té strany, kterou saldokonto už počítá jiné
 * záloze, k přiřazení není:
 *   - vazba konečné faktury na zálohu (`advance_purchase_invoice_id` na zálohu nebo
 *     samostatný daňový doklad k platbě, `parent_invoice_id` na proformu) přiřadí zálohovému
 *     účtu celou stranu faktury, stejně jako ji sčítá saldokonto,
 *   - každý řádek čerpání (převzatý z převodu i zadaný v saldokontu) svou částku.
 * Přeúčtování mezi analytikami zálohového účtu v témže zápisu (stejná částka na obou
 * stranách) čerpáním není.
 *
 * Všechny dotazy začínají omezením na firmu; tenanta dokladů ověřuje {@see lockDocuments()}
 * a {@see documents()}.
 */
final class AdvanceDeductionRepository
{
    /** Druhy konečných dokladů, které čerpají zálohu. */
    private const FINAL_KINDS = [
        'purchase_invoice' => ['invoice', 'receipt'],
        'invoice'          => ['invoice'],
    ];

    public function __construct(private readonly Connection $db) {}

    public static function table(string $docType): string
    {
        return match ($docType) {
            'purchase_invoice' => 'purchase_advance_deductions',
            'invoice'          => 'invoice_advance_deductions',
            default            => throw new \InvalidArgumentException('Neplatný druh dokladu čerpání zálohy.'),
        };
    }

    /** Strana zálohového účtu, kterou konečná faktura zálohu čerpá. */
    public static function deductionSide(string $docType): string
    {
        return $docType === 'invoice' ? 'debit' : 'credit';
    }

    /**
     * Zamkne doklady (FOR UPDATE, vzestupně), než se cokoli přečte: souběžná čerpání téže
     * zálohy nebo téže konečné faktury se seřadí a druhé už počítá s prvním.
     *
     * @param list<int> $ids
     */
    public function lockDocuments(int $supplierId, string $docType, array $ids): void
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return;
        }
        $table = self::docTable($docType);
        $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND id IN (" . implode(',', $ids) . ') ORDER BY id FOR UPDATE')
            ->execute([$supplierId]);
    }

    /**
     * Doklady firmy podle id. Chybějící (cizí i neexistující) ve výsledku nejsou.
     *
     * @param list<int> $ids
     * @return array<int,array{doc_type:string, doc_id:int, kind:string, standalone:bool, partner_id:int, partner_name:string,
     *                         currency_code:string, status:string, issue_date:string, doc_no:string, numbers:list<string>}>
     */
    public function documents(int $supplierId, string $docType, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = $docType === 'invoice'
            ? "SELECT d.id, d.invoice_type AS kind, 1 AS standalone, d.client_id AS partner_id, cl.company_name AS partner_name,
                      cur.code AS currency_code, d.status, d.issue_date, d.varsymbol, NULL AS vendor_invoice_number
                 FROM invoices d
                 JOIN clients cl ON cl.id = d.client_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.supplier_id = ? AND d.id IN ({$in})"
            : "SELECT d.id, d.document_kind AS kind, d.parent_purchase_invoice_id IS NULL AS standalone,
                      d.vendor_id AS partner_id, cl.company_name AS partner_name,
                      cur.code AS currency_code, d.status, d.issue_date, d.varsymbol, d.vendor_invoice_number
                 FROM purchase_invoices d
                 JOIN clients cl ON cl.id = d.vendor_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.supplier_id = ? AND d.id IN ({$in})";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vin = trim((string) ($r['vendor_invoice_number'] ?? ''));
            $vs = trim((string) ($r['varsymbol'] ?? ''));
            $out[(int) $r['id']] = [
                'doc_type'      => $docType,
                'doc_id'        => (int) $r['id'],
                'kind'          => (string) $r['kind'],
                'standalone'    => (int) $r['standalone'] === 1,
                'partner_id'    => (int) $r['partner_id'],
                'partner_name'  => (string) $r['partner_name'],
                'currency_code' => strtoupper((string) $r['currency_code']),
                'status'        => (string) $r['status'],
                'issue_date'    => (string) $r['issue_date'],
                'doc_no'        => $vin !== '' ? $vin : ($vs !== '' ? $vs : '#' . (int) $r['id']),
                'numbers'       => array_values(array_unique(array_filter([$vin, $vs], [JournalSettlementRepository::class, 'isDocumentNumber']))),
            ];
        }
        return $out;
    }

    /**
     * Konečné faktury partnerů `$partnerIds`, které mají k `$asOf` zaúčtovanou stranu
     * čerpání na zálohovém účtu (`$accountIds` = účet a jeho analytiky), s částkou, kterou
     * zatím nečerpá žádná záloha (`unassigned`). `posted_on` = den zaúčtování vlastním
     * zápisem faktury: od něj saldokonto čerpání počítá, faktura bez něj čerpat nemůže.
     * `texts` = popisy řádků faktury a text zápisu pro hledání čísla zálohy.
     *
     * @param list<int> $accountIds
     * @param list<int> $partnerIds
     * @param list<int>|null $finalIds zúžení na konkrétní faktury
     * @return array<int,array{doc_id:int, partner_id:int, doc_no:string, issue_date:string, posted_on:?string,
     *                         posted_entry_id:?int, period_status:?string, deductible:float, linked:bool,
     *                         deducted:float, unassigned:float, texts:list<string>}>
     */
    public function finals(int $supplierId, string $docType, array $accountIds, array $partnerIds, string $asOf, ?array $finalIds = null): array
    {
        $accountIds = self::ids($accountIds);
        $partnerIds = self::ids($partnerIds);
        if ($accountIds === [] || $partnerIds === [] || $finalIds === []) {
            return [];
        }
        $isInvoice = $docType === 'invoice';
        $table = self::docTable($docType);
        $partnerCol = $isInvoice ? 'client_id' : 'vendor_id';
        $kindCol = $isInvoice ? 'invoice_type' : 'document_kind';
        $kinds = "'" . implode("','", self::FINAL_KINDS[$docType]) . "'";
        $numberSql = $isInvoice
            ? "COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id))"
            : "COALESCE(NULLIF(d.vendor_invoice_number, ''), NULLIF(d.varsymbol, ''), CONCAT('#', d.id))";
        // Vazba, se kterou saldokonto přičte celou stranu faktury jedné záloze.
        $linkedSql = $isInvoice
            ? "EXISTS (SELECT 1 FROM invoices la WHERE la.supplier_id = d.supplier_id AND la.id = d.parent_invoice_id
                         AND la.invoice_type = 'proforma')"
            : "EXISTS (SELECT 1 FROM purchase_invoices la WHERE la.supplier_id = d.supplier_id AND la.id = d.advance_purchase_invoice_id
                         AND (la.document_kind = 'advance' OR (la.document_kind = 'tax_document' AND la.parent_purchase_invoice_id IS NULL)))";
        $only = $finalIds === null ? '' : ' AND d.id IN (' . implode(',', self::ids($finalIds) ?: [0]) . ')';
        $accounts = implode(',', $accountIds);
        $sql = "SELECT d.id, d.{$partnerCol} AS partner_id, {$numberSql} AS doc_no, d.issue_date,
                       {$linkedSql} AS linked,
                       e.id AS entry_id, e.description AS entry_description, l.account_id, l.side, l.signed_amount
                  FROM {$table} d
                  JOIN currencies cur ON cur.id = d.currency_id
                  JOIN (" . DocumentPostingGroup::entriesSql($docType, $supplierId) . ") de ON de.doc_id = d.id
                  JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.id = de.entry_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                       AND l.account_id IN ({$accounts})
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE d.supplier_id = {$supplierId} AND d.{$partnerCol} IN (" . implode(',', $partnerIds) . ")
                   AND d.{$kindCol} IN ({$kinds}) AND d.status NOT IN ('draft', 'cancelled') AND cur.code = 'CZK'{$only}
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ? AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                 ORDER BY d.id, e.id, l.line_no, l.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$asOf, $asOf]);
        $side = self::deductionSide($docType);
        $docs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $docs[$id] ??= [
                'doc_id'     => $id,
                'partner_id' => (int) $r['partner_id'],
                'doc_no'     => (string) $r['doc_no'],
                'issue_date' => (string) $r['issue_date'],
                'linked'     => (int) $r['linked'] === 1,
                'entries'    => [],
                'texts'      => [],
            ];
            $docs[$id]['entries'][(int) $r['entry_id']][] = $r;
            if ($r['entry_description'] !== null) {
                $docs[$id]['texts'][(int) $r['entry_id']] = (string) $r['entry_description'];
            }
        }
        if ($docs === []) {
            return [];
        }
        $ids = array_keys($docs);
        $deducted = $this->deductedByFinal($supplierId, $docType, $ids);
        $posted = $this->postedEntries($supplierId, $docType, $ids, $asOf);
        $items = $this->itemTexts($docType, $ids);

        $out = [];
        foreach ($docs as $id => $doc) {
            $deductible = 0.0;
            foreach ($doc['entries'] as $lines) {
                $deductible += self::deductibleOfEntry($lines, $side);
            }
            $deductible = round($deductible, 2);
            if ($deductible < 0.005) {
                continue;
            }
            $taken = round(($doc['linked'] ? $deductible : 0.0) + ($deducted[$id] ?? 0.0), 2);
            $out[$id] = [
                'doc_id'          => $id,
                'partner_id'      => $doc['partner_id'],
                'doc_no'          => $doc['doc_no'],
                'issue_date'      => $doc['issue_date'],
                'posted_on'       => $posted[$id]['entry_date'] ?? null,
                'posted_entry_id' => $posted[$id]['entry_id'] ?? null,
                'period_status'   => $posted[$id]['period_status'] ?? null,
                'deductible'      => $deductible,
                'linked'          => $doc['linked'],
                'deducted'        => round($deducted[$id] ?? 0.0, 2),
                'unassigned'      => max(0.0, round($deductible - $taken, 2)),
                'texts'           => array_values([...array_values($doc['texts']), ...($items[$id] ?? [])]),
            ];
        }
        return $out;
    }

    /**
     * Čerpání zálohy (obě strany vazby se čtou stejně; `$by` = 'advance' nebo 'final').
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $supplierId, string $docType, string $by, int $docId): array
    {
        $table = self::table($docType);
        $docTable = self::docTable($docType);
        $numberSql = $docType === 'invoice'
            ? "COALESCE(NULLIF(%s.varsymbol, ''), CONCAT('#', %s.id))"
            : "COALESCE(NULLIF(%s.vendor_invoice_number, ''), NULLIF(%s.varsymbol, ''), CONCAT('#', %s.id))";
        $no = static fn (string $a): string => str_replace('%s', $a, $numberSql);
        $column = $by === 'final' ? 'final_invoice_id' : 'advance_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.final_invoice_id, t.advance_invoice_id, t.amount, t.source, t.created_by, t.created_at,
                    {$no('f')} AS final_no, {$no('a')} AS advance_no,
                    (SELECT MIN(fe.entry_date) FROM journal_entries fe
                      WHERE fe.supplier_id = t.supplier_id AND fe.source_type = '{$docType}' AND fe.source_id = t.final_invoice_id
                        AND fe.posted_at IS NOT NULL AND fe.reversed_by IS NULL) AS posted_on
               FROM {$table} t
               JOIN {$docTable} f ON f.id = t.final_invoice_id AND f.supplier_id = t.supplier_id
               JOIN {$docTable} a ON a.id = t.advance_invoice_id AND a.supplier_id = t.supplier_id
              WHERE t.supplier_id = ? AND t.{$column} = ?
              ORDER BY t.id"
        );
        $stmt->execute([$supplierId, $docId]);
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'doc_type'     => $docType,
            'final_id'     => (int) $r['final_invoice_id'],
            'final_no'     => (string) $r['final_no'],
            'advance_id'   => (int) $r['advance_invoice_id'],
            'advance_no'   => (string) $r['advance_no'],
            'amount'       => round((float) $r['amount'], 2),
            'source'       => (string) $r['source'],
            'posted_on'    => $r['posted_on'] !== null ? (string) $r['posted_on'] : null,
            'created_by'   => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at'   => (string) $r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Čerpání téže dvojice (zamčené), nebo null. */
    public function pair(int $supplierId, string $docType, int $finalId, int $advanceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source, amount FROM ' . self::table($docType) . '
              WHERE supplier_id = ? AND final_invoice_id = ? AND advance_invoice_id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $finalId, $advanceId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'amount' => round((float) $r['amount'], 2)];
    }

    /** Ruční čerpání z dialogu saldokonta; opakované čerpání téže dvojice se přičte. */
    public function insert(int $supplierId, string $docType, int $finalId, int $advanceId, float $amount, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO ' . self::table($docType) . " (supplier_id, final_invoice_id, advance_invoice_id, amount, source, created_by)
             VALUES (?, ?, ?, ?, 'saldo', ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), amount = amount + VALUES(amount)"
        )->execute([$supplierId, $finalId, $advanceId, round($amount, 2), $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{id:int, final_id:int, advance_id:int, amount:float, source:string}|null */
    public function find(int $supplierId, string $docType, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, final_invoice_id, advance_invoice_id, amount, source FROM ' . self::table($docType)
            . ' WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : [
            'id'         => (int) $r['id'],
            'final_id'   => (int) $r['final_invoice_id'],
            'advance_id' => (int) $r['advance_invoice_id'],
            'amount'     => round((float) $r['amount'], 2),
            'source'     => (string) $r['source'],
        ];
    }

    public function delete(int $supplierId, string $docType, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . self::table($docType) . ' WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Zápis konečné faktury, od kterého čerpání platí (nejstarší živý vlastní zápis), se
     * stavem jeho účetního období.
     *
     * @param list<int> $ids
     * @return array<int,array{entry_id:int, entry_date:string, period_status:?string}>
     */
    public function postedEntries(int $supplierId, string $docType, array $ids, string $asOf = '9999-12-31'): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT fe.source_id, fe.id, fe.entry_date, ap.status
               FROM journal_entries fe
               LEFT JOIN journal_entries frev ON frev.id = fe.reversed_by
               LEFT JOIN accounting_periods ap ON ap.id = fe.period_id AND ap.supplier_id = fe.supplier_id
              WHERE fe.supplier_id = ? AND fe.source_type = ? AND fe.source_id IN (" . implode(',', $ids) . ")
                AND fe.posted_at IS NOT NULL AND fe.entry_date <= ? AND (fe.reversed_by IS NULL OR frev.entry_date > ?)
              ORDER BY fe.entry_date, fe.id"
        );
        $stmt->execute([$supplierId, $docType, $asOf, $asOf]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['source_id']] ??= [
                'entry_id'      => (int) $r['id'],
                'entry_date'    => (string) $r['entry_date'],
                'period_status' => $r['status'] !== null ? (string) $r['status'] : null,
            ];
        }
        return $out;
    }

    /**
     * Strana čerpání jednoho zápisu bez přeúčtování mezi analytikami zálohového účtu.
     *
     * @param list<array<string,mixed>> $lines řádky zápisu na zálohovém účtu
     */
    private static function deductibleOfEntry(array $lines, string $side): float
    {
        $opposite = [];
        foreach ($lines as $l) {
            if ($l['side'] !== $side) {
                $opposite[] = [(int) $l['account_id'], round((float) $l['signed_amount'], 2)];
            }
        }
        $sum = 0.0;
        foreach ($lines as $l) {
            if ($l['side'] !== $side) {
                continue;
            }
            $amount = round((float) $l['signed_amount'], 2);
            foreach ($opposite as $i => [$account, $value]) {
                if ($account !== (int) $l['account_id'] && abs($value - $amount) < 0.005) {
                    unset($opposite[$i]);
                    continue 2;
                }
            }
            $sum += $amount;
        }
        return $sum;
    }

    /**
     * @param list<int> $ids
     * @return array<int,float>
     */
    private function deductedByFinal(int $supplierId, string $docType, array $ids): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT final_invoice_id, SUM(amount) FROM ' . self::table($docType) . '
              WHERE supplier_id = ? AND final_invoice_id IN (' . implode(',', self::ids($ids)) . ')
              GROUP BY final_invoice_id'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $sum]) {
            $out[(int) $id] = round((float) $sum, 2);
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<string>>
     */
    private function itemTexts(string $docType, array $ids): array
    {
        $sql = $docType === 'invoice'
            ? 'SELECT invoice_id, description FROM invoice_items WHERE invoice_id IN (%s)'
            : 'SELECT purchase_invoice_id, description FROM purchase_invoice_items WHERE purchase_invoice_id IN (%s)';
        $stmt = $this->db->pdo()->query(sprintf($sql, implode(',', self::ids($ids))));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $text]) {
            if ($text !== null && trim((string) $text) !== '') {
                $out[(int) $id][] = (string) $text;
            }
        }
        return $out;
    }

    private static function docTable(string $docType): string
    {
        return match ($docType) {
            'purchase_invoice' => 'purchase_invoices',
            'invoice'          => 'invoices',
            default            => throw new \InvalidArgumentException('Neplatný druh dokladu čerpání zálohy.'),
        };
    }

    /** @return list<int> kladná id bez duplicit, vzestupně */
    private static function ids(array $ids): array
    {
        $out = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($out);
        return $out;
    }
}
