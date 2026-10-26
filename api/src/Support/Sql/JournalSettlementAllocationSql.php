<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro rozpad řádku zápisu na doklady saldokonta
 * (`journal_settlement_allocations`, migrace 2192).
 *
 * Řádek se drží přes (entry_id, line_no) a účet v okamžiku rozpadu; řádek, který po
 * přeúčtování na tom pořadí nese jiný účet, se nepočítá ({@see lineJoin}). Platnost
 * k rozvahovému dni měří stejně jako hlavní kniha: `entry_date` zápisu a jeho protizápisu.
 *
 * Volají ji {@see LinkedManualSettlementSql::settledSql} (vyrovnání dokladů v saldokontu
 * i v uzávěrkové kontrole K3), `SaldoRepository` (předpis ostatní položky založené ze
 * zůstatku) a `JournalSettlementRepository` (volná částka řádku).
 */
final class JournalSettlementAllocationSql
{
    /**
     * Agregace `doc_id`, `settled` (Kč, kladné = snižuje saldo dokladu) z rozpadů druhu
     * `settlement`. Placeholdery podle předaných výrazů: `$supplierExpr` a dvakrát `$asOfExpr`.
     * Podmínka účtu dostává aliasy `ca` (účet řádku) a `pa` (jeho rodič).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public static function settledSql(
        string $docType,
        string $accountPredicate,
        string $supplierExpr = '?',
        string $asOfExpr = '?',
        bool $currentRead = false,
    ): string {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('journal_settlement_invalid');
        }
        $lock = $currentRead ? ' FOR UPDATE' : '';
        return "SELECT ja.doc_id, SUM(ja.amount) AS settled
                  FROM journal_settlement_allocations ja
                  JOIN journal_entries e ON e.id = ja.entry_id AND e.supplier_id = ja.supplier_id
                  " . self::lineJoin('ja', 'l') . "
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE ja.supplier_id = {$supplierExpr} AND ja.doc_type = '{$docType}' AND ja.kind = 'settlement'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= {$asOfExpr}
                   AND (e.reversed_by IS NULL OR rev.entry_date > {$asOfExpr})
                   AND ({$accountPredicate})
                 GROUP BY ja.doc_id{$lock}";
    }

    /**
     * Rozpad platí jen pro řádek, který na pořadí `line_no` pořád nese účet z okamžiku
     * rozpadu (vzor párování okruhů). `$a` = alias rozpadu, `$l` = alias řádku.
     */
    public static function lineJoin(string $a, string $l): string
    {
        return "JOIN journal_entry_lines {$l} ON {$l}.supplier_id = {$a}.supplier_id AND {$l}.entry_id = {$a}.entry_id
                     AND {$l}.line_no = {$a}.line_no AND {$l}.account_id = {$a}.account_id";
    }

    /**
     * `NOT EXISTS`: zápis `$entryExpr` nemá rozpad na doklady druhu `$docTypeExpr`. Zápis
     * s rozpadem se ve staré vazbě bez částky nepočítá, jinak by se vyrovnání započetlo dvakrát.
     */
    public static function noAllocationSql(string $supplierExpr, string $entryExpr, string $docTypeExpr, bool $currentRead = false): string
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        return "NOT EXISTS (
                   SELECT 1 FROM journal_settlement_allocations jsa
                    WHERE jsa.supplier_id = {$supplierExpr} AND jsa.entry_id = {$entryExpr}
                      AND jsa.doc_type = {$docTypeExpr}{$lock}
               )";
    }

    /**
     * Použitá částka řádků (Kč): rozpady na doklady i předpisy živých ostatních položek.
     * Výstup `entry_id`, `line_no`, `account_id`, `used`. Bez placeholderů, ID literálem.
     *
     * @param list<int> $accountIds
     */
    public static function usedByLineSql(int $supplierId, array $accountIds): string
    {
        $ids = implode(',', array_map('intval', $accountIds ?: [0]));
        return "SELECT u.entry_id, u.line_no, u.account_id, SUM(u.amount) AS used
                  FROM journal_settlement_allocations u
                  LEFT JOIN other_items uoi ON u.kind = 'origin' AND uoi.id = u.doc_id AND uoi.supplier_id = u.supplier_id
                 WHERE u.supplier_id = {$supplierId} AND u.account_id IN ({$ids})
                   AND (u.kind = 'settlement' OR (uoi.deleted_at IS NULL AND uoi.status IN ('confirmed', 'posted')))
                 GROUP BY u.entry_id, u.line_no, u.account_id";
    }
}
