<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Čerpání záloh z Money: vazby konečné faktury na zálohovou fakturu a daňového dokladu
 * k přijaté platbě (DDKP) na zálohu. Bez nich saldokonto 314/324 nepozná, že je záloha
 * vyúčtovaná, a drží ji otevřenou v plné výši.
 *
 * Money vede odpočet zálohy jako položku konečné faktury (`VFaktPol` / `PFaktPol`,
 * `Zaloha = 1`) s odkazem na zálohovou fakturu: `CisloZaFak` je interní číslo záznamu
 * zálohy, `RokZaFak` adresář roku (prázdný = tentýž rok). Odkaz se přijme jen na zálohovou
 * fakturu (druh L/Z/F), jejíž číslo položka v textu uvádí („Odpočet zálohy ZF190001"),
 * jinak Money odkazuje i na bankovní doklad úhrady. Čerpaná částka je cena položky
 * (u plátce základ, DPH čerpá DDKP).
 *
 * Přijatá faktura, která odečítá právě jednu zálohu čerpanou jen jí, dostane vazbu na
 * dokladu (`advance_purchase_invoice_id`); ostatní čerpání přijatých záloh jde do
 * `purchase_advance_deductions`. Vydaná faktura s jedinou zálohou dostane rodiče
 * (`parent_invoice_id`), víc záloh zůstane v protokolu k ruční kontrole.
 *
 * DDKP Money s zálohou svazuje jen textem, který samo generuje: položka odpočtu
 * („… (daň. dokl. DPP001)") a popis DDKP („Doklad k přijaté platbě (záloha ZF190001)",
 * případně s bankovním dokladem úhrady zálohy „(záloha BV190038)"). Vazba se přijme jen
 * k záloze téhož partnera. Vrácení zálohy příchozí platbou se spáruje se zálohou
 * ({@see matchAdvanceRefunds()}). Čerpání, které Money vede bez odkazu na zálohu (řádek
 * konečné faktury na zálohovém účtu s vlastním symbolem faktury), spáruje podle saldokonta
 * {@see AdvanceDeductionMatcher}.
 *
 * Existující vazby převod nepřepisuje a opakovaný převod nic nezdvojí.
 */
final class AdvanceLinker
{
    private const ADVANCE_KINDS = ['L', 'Z', 'F'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?MoneyS3ImportRepository $map = null,
    ) {}

    public function link(ImportContext $ctx): void
    {
        $dirs = [];
        foreach ($ctx->dirYears as $dir => $year) {
            if (isset($ctx->periods[$year])) {
                $dirs[] = $dir;
            }
        }
        if ($dirs === []) {
            return;
        }
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            $parts = explode('|', (string) $key, 3);
            $bankByDoc[$parts[0] . '|' . ($parts[1] ?? '')][] = (int) $id;
        }
        foreach ([
            ['VFaktury', 'VFaktPol', $ctx->issuedInvoices, true],
            ['PFaktury', 'PFaktPol', $ctx->purchaseInvoices, false],
        ] as [$headTable, $itemTable, $docs, $issued]) {
            $heads = [];
            foreach ($ctx->backup->rowsAcrossYears($headTable, ['Cislo', 'Doklad', 'Druh', 'Popis'], $dirs) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $id = $docs[$year . '|' . $docNo] ?? null;
                if ($id === null) {
                    continue;
                }
                $heads[$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] = [
                    'id' => (int) $id, 'year' => (int) $year, 'doc' => $docNo,
                    'kind' => strtoupper(trim((string) ($r['Druh'] ?? ''))), 'text' => (string) ($r['Popis'] ?? ''),
                ];
            }
            $deductions = [];
            $taxDocuments = [];
            foreach ($ctx->backup->rowsAcrossYears($itemTable, ['Cislo', 'Popis', 'Cena', 'PocetMJ', 'Zaloha', 'CisloZaFak', 'RokZaFak'], $dirs) as $r) {
                if ((int) ($r['Zaloha'] ?? 0) !== 1 || (int) ($r['CisloZaFak'] ?? 0) <= 0) {
                    continue;
                }
                $final = $heads[$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] ?? null;
                $rok = trim((string) ($r['RokZaFak'] ?? ''));
                $advance = $heads[($rok !== '' ? 'ROK.' . str_pad($rok, 3, '0', STR_PAD_LEFT) : $r['__dir']) . '|' . (int) $r['CisloZaFak']] ?? null;
                $text = (string) ($r['Popis'] ?? '');
                if ($final === null || $advance === null || !in_array($advance['kind'], self::ADVANCE_KINDS, true)
                    || in_array($final['kind'], self::ADVANCE_KINDS, true) || !str_contains($text, $advance['doc'])) {
                    continue;
                }
                $amount = round(abs((float) ($r['Cena'] ?? 0) * (float) (($r['PocetMJ'] ?? 0) ?: 1)), 2);
                $deductions[$final['id']][$advance['id']] = round(($deductions[$final['id']][$advance['id']] ?? 0.0) + $amount, 2);
                if (preg_match('/da[nň]\.?\s*dokl\.?\s*([^\s)]+)/u', $text, $m) === 1) {
                    $taxDocuments[$final['year'] . '|' . $m[1]][$advance['id']] = true;
                }
            }
            // Popis DDKP („Doklad k přijaté platbě (záloha ZF190001)") - záloha z téhož nebo minulého roku.
            $advanceByNo = [];
            foreach ($heads as $h) {
                if (in_array($h['kind'], self::ADVANCE_KINDS, true)) {
                    $advanceByNo[$h['year'] . '|' . $h['doc']] = $h['id'];
                }
            }
            $ddkp = [];
            foreach ($heads as $h) {
                if ($h['kind'] !== 'D') {
                    continue;
                }
                foreach ($taxDocuments[$h['year'] . '|' . $h['doc']] ?? [] as $advanceId => $_) {
                    $ddkp[$h['id']][$advanceId] = true;
                }
                if (preg_match('/\(z[aá]loha\s+([^\s)]+)\)/u', $h['text'], $m) === 1) {
                    // Odkaz na zálohovou fakturu, nebo na bankovní doklad, kterým byla záloha zaplacena.
                    $advanceId = $advanceByNo[$h['year'] . '|' . $m[1]] ?? $advanceByNo[($h['year'] - 1) . '|' . $m[1]]
                        ?? $this->advancePaidBy($ctx, $issued, $bankByDoc[$h['year'] . '|' . $m[1]] ?? $bankByDoc[($h['year'] - 1) . '|' . $m[1]] ?? []);
                    if ($advanceId !== null) {
                        $ddkp[$h['id']][$advanceId] = true;
                    }
                }
            }
            $issued ? $this->linkIssued($ctx, $deductions, $ddkp) : $this->linkPurchase($ctx, $deductions, $ddkp);
        }
        $this->matchAdvanceRefunds($ctx);
        (new AdvanceDeductionMatcher($this->db, $this->map))->match($ctx, $dirs);
    }

    /**
     * Zálohová faktura, kterou uhradil jediný bankovní pohyb z dokladu Money (DDKP Money
     * odkazuje i na bankovní doklad úhrady, „záloha BV190038").
     *
     * @param list<int> $txIds
     */
    private function advancePaidBy(ImportContext $ctx, bool $issued, array $txIds): ?int
    {
        if (count($txIds) !== 1) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare($issued
            ? "SELECT DISTINCT d.id FROM payment_matches pm JOIN invoices d ON d.id = pm.invoice_id AND d.supplier_id = pm.supplier_id AND d.invoice_type = 'proforma'
                WHERE pm.supplier_id = ? AND pm.bank_transaction_id = ?"
            : "SELECT DISTINCT d.id FROM payment_matches pm JOIN purchase_invoices d ON d.id = pm.purchase_invoice_id AND d.supplier_id = pm.supplier_id AND d.document_kind = 'advance'
                WHERE pm.supplier_id = ? AND pm.bank_transaction_id = ?");
        $stmt->execute([$ctx->supplierId, $txIds[0]]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /**
     * Vrácení poskytnuté zálohy: příchozí platba zaúčtovaná v Money na zálohový účet (314),
     * nespárovaná, s variabilním symbolem zálohy (číslo dodavatele nebo VS), se spáruje se
     * zálohou - je-li taková záloha právě jedna a vrácená částka nepřesahuje, co na ni bylo
     * zaplaceno a ještě nevráceno. Saldokonto pak zálohu vrácením uzavře. Jen pohyb
     * zaúčtovaný převzatým zápisem; opakovaný převod pohyb spárovaný jinde (i ručně v MyÚčtu)
     * ani pohyb zaúčtovaný v MyÚčtu nemění.
     */
    private function matchAdvanceRefunds(ImportContext $ctx): void
    {
        $pdo = $this->db->pdo();
        $advances = $pdo->prepare(
            "SELECT a.id, a.vendor_invoice_number, a.payment_variable_symbol,
                    (SELECT COALESCE(SUM(CASE WHEN bt.amount < 0 THEN pm.amount ELSE -pm.amount END), 0)
                       FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                      WHERE pm.supplier_id = a.supplier_id AND pm.purchase_invoice_id = a.id) AS net_paid
               FROM purchase_invoices a
              WHERE a.supplier_id = ? AND a.document_kind = 'advance' AND a.status NOT IN ('draft', 'cancelled')
                AND " . TakenOverRecord::documentSql('purchase_invoice', 'a')
        );
        $advances->execute([$ctx->supplierId]);
        $left = [];
        $bySymbol = [];
        while ($r = $advances->fetch(PDO::FETCH_ASSOC)) {
            $left[(int) $r['id']] = (float) $r['net_paid'];
            foreach (array_unique([(string) $r['vendor_invoice_number'], (string) $r['payment_variable_symbol']]) as $symbol) {
                $digits = ltrim(preg_replace('/\D+/', '', $symbol) ?? '', '0');
                if ($digits !== '') {
                    $bySymbol[$digits][(int) $r['id']] = true;
                }
            }
        }
        if ($left === []) {
            return;
        }
        $refunds = $pdo->prepare(
            "SELECT bt.id, bt.amount, bt.variable_symbol
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = ? AND bt.amount > 0 AND bt.variable_symbol IS NOT NULL AND bt.variable_symbol <> ''
                AND (bt.match_status = 'unmatched' OR (bt.match_status = 'ignored' AND bt.match_reason = 'money_s3_booked'))
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM other_item_allocations oia WHERE oia.bank_transaction_id = bt.id)
                AND EXISTS (SELECT 1 FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                              JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                             WHERE e.supplier_id = bs.supplier_id AND e.source_type = 'bank' AND e.source_id = bt.id AND e.reversed_by IS NULL
                               AND l.side = 'credit' AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')
                               AND " . TakenOverRecord::journalEntrySql('e') . ')'
        );
        $refunds->execute([$ctx->supplierId]);
        $match = $pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id) VALUES (?, ?, NULL, ?, ?, "manual", ?)');
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.ignore_note = IF(t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked', NULL, t.ignore_note),
                    t.match_reason = IF(t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked', NULL, t.match_reason),
                    t.match_status = 'manual', t.matched_at = COALESCE(t.matched_at, NOW()), t.matched_by = COALESCE(t.matched_by, ?)
              WHERE t.id = ? AND s.supplier_id = ? AND (t.match_status = 'unmatched' OR (t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked'))"
        );
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        foreach ($refunds->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $candidates = array_keys($bySymbol[ltrim(preg_replace('/\D+/', '', (string) $t['variable_symbol']) ?? '', '0')] ?? []);
            if (count($candidates) !== 1 || $left[$candidates[0]] + 0.005 < (float) $t['amount']) {
                continue;
            }
            $match->execute([$ctx->supplierId, (int) $t['id'], $candidates[0], number_format((float) $t['amount'], 2, '.', ''), $userId]);
            $this->map?->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, DocumentLinker::paymentMapKey((int) $t['id'], 'purchase_invoice', $candidates[0]),
                (int) $pdo->lastInsertId(), $ctx->runId);
            $markTx->execute([$userId, (int) $t['id'], $ctx->supplierId]);
            $left[$candidates[0]] -= (float) $t['amount'];
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_refund_matched');
        }
    }

    /**
     * @param array<int,array<int,float>> $deductions konečná faktura => záloha => čerpáno
     * @param array<int,array<int,true>> $ddkp DDKP => zálohy
     */
    private function linkPurchase(ImportContext $ctx, array $deductions, array $ddkp): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $finalsOf = [];
        foreach ($deductions as $finalId => $advances) {
            foreach ($advances as $advanceId => $_) {
                $finalsOf[$advanceId][$finalId] = true;
            }
        }
        $current = $pdo->prepare("SELECT advance_purchase_invoice_id FROM purchase_invoices WHERE id = ? AND supplier_id = ? AND document_kind = 'invoice'");
        $taken = $pdo->prepare('SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND advance_purchase_invoice_id = ? LIMIT 1');
        $set = $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ? AND supplier_id = ? AND advance_purchase_invoice_id IS NULL');
        $deduct = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        $live = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id = ? AND document_kind = 'advance'");
        $live->execute([$ctx->supplierId]);
        $liveAdvances = array_fill_keys(array_map('intval', $live->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($deductions as $finalId => $advances) {
            $current->execute([$finalId, $ctx->supplierId]);
            $linked = $current->fetchColumn();
            if ($linked === false) {
                continue; // konečná faktura převedená jinak než jako běžná faktura (nebo smazaná)
            }
            $linked = $linked !== null ? (int) $linked : null;
            // Zálohy smazané v MyÚčtu se vynechají.
            $advances = array_intersect_key($advances, $liveAdvances);
            if ($advances === []) {
                continue;
            }
            if ($linked !== null && !isset($advances[$linked])) {
                // Vazbu na jinou zálohu nastavil uživatel; čerpání z Money se k ní nepřidává.
                $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_user_kept');
                continue;
            }
            if (count($advances) === 1 && count($finalsOf[(int) array_key_first($advances)]) === 1) {
                $advanceId = (int) array_key_first($advances);
                if ($linked === $advanceId) {
                    continue;
                }
                $taken->execute([$ctx->supplierId, $advanceId]);
                if ($linked === null && $taken->fetchColumn() === false) {
                    $set->execute([$advanceId, $finalId, $ctx->supplierId]);
                    if ($set->rowCount() > 0) {
                        $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_linked');
                    }
                    continue;
                }
            }
            foreach ($advances as $advanceId => $amount) {
                if ($linked === $advanceId || $amount < 0.01) {
                    continue;
                }
                $deduct->execute([$ctx->supplierId, $finalId, $advanceId, number_format($amount, 2, '.', ''), $userId]);
                if ($deduct->rowCount() > 0) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_deductions');
                }
            }
        }
        $this->linkTaxDocuments($ctx, $ddkp, 'purchase_invoices', 'parent_purchase_invoice_id', 'document_kind', 'vendor_id');
    }

    /**
     * @param array<int,array<int,float>> $deductions
     * @param array<int,array<int,true>> $ddkp
     */
    private function linkIssued(ImportContext $ctx, array $deductions, array $ddkp): void
    {
        $p = $ctx->protocol;
        $set = $this->db->pdo()->prepare(
            "UPDATE invoices SET parent_invoice_id = ? WHERE id = ? AND supplier_id = ? AND parent_invoice_id IS NULL AND invoice_type = 'invoice'"
        );
        foreach ($deductions as $finalId => $advances) {
            if (count($advances) !== 1) {
                $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_multiple');
                continue;
            }
            $set->execute([(int) array_key_first($advances), $finalId, $ctx->supplierId]);
            if ($set->rowCount() > 0) {
                $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_linked');
            }
        }
        $this->linkTaxDocuments($ctx, $ddkp, 'invoices', 'parent_invoice_id', 'invoice_type', 'client_id');
    }

    /** @param array<int,array<int,true>> $ddkp */
    private function linkTaxDocuments(ImportContext $ctx, array $ddkp, string $table, string $column, string $kindColumn, string $partner): void
    {
        $set = $this->db->pdo()->prepare(
            "UPDATE {$table} t JOIN {$table} a ON a.id = ? AND a.supplier_id = t.supplier_id AND a.{$partner} = t.{$partner}
                SET t.{$column} = a.id
              WHERE t.id = ? AND t.supplier_id = ? AND t.{$column} IS NULL AND t.{$kindColumn} = 'tax_document'"
        );
        foreach ($ddkp as $taxDocumentId => $advances) {
            if (count($advances) !== 1) {
                continue;
            }
            $set->execute([(int) array_key_first($advances), $taxDocumentId, $ctx->supplierId]);
            if ($set->rowCount() > 0) {
                $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'tax_document_advance_linked');
            }
        }
    }
}
