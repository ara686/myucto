<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis z Money dostane `source_id` = id dokladu a vazbu
 * v `journal_entry_document_links`. Na klíči (typ, id dokladu) stojí v MyÚčtu všechno,
 * co se ptá „je doklad zaúčtovaný" — úhrada na detailu faktury, saldokonto, kontroly
 * uzávěrky i automatika. **U bankovního zápisu je `source_id` id pohybu**; bez něj
 * detail faktury hlásí „úhrada NENÍ zaúčtovaná" a automatika by pohyb zaúčtovala znovu.
 *
 * Páruje se podle čísla dokladu Money, které nese doklad i řádek deníku — ne podle
 * částky a data, kde by dvě stejné platby téhož dne splynuly.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    private readonly JournalEntryLinker $entries;

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
    ) {
        $this->entries = new JournalEntryLinker($db, 'Money S3');
    }

    public function link(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        // Index deníku jen roku, jehož doklady se právě navazují (doklady jdou po letech).
        $index = [];
        $indexYear = null;
        // Koncept k ruční kontrole (zálohová faktura, doklad nejisté daňové povahy)
        // v deníku Money zápis mít nemusí a zaúčtuje se až po potvrzení účetní.
        $drafts = [
            'purchase_invoice' => $this->draftIds('purchase_invoices', $ctx->supplierId),
            'invoice' => $this->draftIds('invoices', $ctx->supplierId),
        ];
        $orphans = [];
        // Ostatní pohledávky vede Money v deníku pod zdrojem KP (převod ho čte jako ruční
        // zápis); navázaný zápis se přeznačí na zápis vydaného dokladu — jen tak ho kontrola
        // 343 i Doúčtování dokladů poznají jako zaúčtovaný doklad.
        foreach ([
            ['purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices, null],
            ['invoice', 'invoice', $ctx->issuedInvoices, null],
            ['cash', 'cash', $ctx->cashDocuments, null],
            ['bank', 'bank', $ctx->bankTransactions, null],
            ['manual', 'invoice', $ctx->otherReceivables, 'invoice'],
        ] as [$sourceType, $docType, $docs, $retype]) {
            foreach ($docs as $key => $docId) {
                [$year, $docNo] = explode('|', (string) $key, 3);
                if ($indexYear !== (int) $year) {
                    $indexYear = (int) $year;
                    $index = $this->journalIndex($ctx->supplierId, $indexYear);
                }
                $entries = $index[$sourceType][$docNo] ?? [];
                if (count($entries) > 1 && in_array($docType, ['bank', 'cash'], true)) {
                    // Stejné číslo dokladu na dvou účtech (pokladnách): zápis se pozná podle data.
                    $date = $this->documentDate($ctx->supplierId, $docType, $docId);
                    $sameDay = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
                    $entries = $sameDay !== [] ? $sameDay : $entries;
                }
                $entries = array_column($entries, 'id');
                if ($entries === []) {
                    if (isset($drafts[$docType][$docId])) {
                        $p->count(self::STEP_LINK, 'review_unlinked');
                        continue;
                    }
                    if (count($orphans) < ImportProtocol::LIST_LIMIT) {
                        $orphans[] = ['type' => $docType, 'year' => (int) $year, 'document_no' => $docNo, 'id' => $docId];
                    }
                    $p->count(self::STEP_LINK, 'orphans');
                    continue;
                }
                $new = $this->entries->attach($ctx->supplierId, $ctx->userId > 0 ? $ctx->userId : null, $retype ?? $sourceType, $sourceType, $docType, $docId, $entries);
                $p->count(self::STEP_LINK, $new ? 'linked' : 'existing');
            }
        }
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' dokladů nemá v deníku Money zápis se stejným číslem. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.');
        }
        $this->entries->refreshDescriptions($ctx->supplierId, $p, self::STEP_LINK);
        $p->finish(self::STEP_LINK);
    }

    /**
     * Úhrady: Money u faktury drží číslo dokladu, kterým byla uhrazena (`UDoklad`). Číslo
     * se ale v Money každý rok opakuje, takže z pohybů se stejným číslem rozhoduje
     * částka a datum úhrady ({@see choosePayment()}), ne jen rok.
     */
    public function matchPayments(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $txRow = $pdo->prepare(
            'SELECT t.amount, DATE(t.posted_at) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ?'
        );
        $cashRow = $pdo->prepare('SELECT total_amount, issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 3);
            $candidate = self::candidate($txRow, $id, (int) $year, $ctx->supplierId);
            // Pohyb eurového účtu je v eurech, faktury z Money v Kč — porovnává se částka v Kč.
            if (isset($ctx->bankTransactionCzk[$id])) {
                $candidate['amount'] = $ctx->bankTransactionCzk[$id];
            }
            $bankByDoc[$docNo][] = $candidate;
        }
        $cashByDoc = [];
        foreach ($ctx->cashDocuments as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 3);
            $cashByDoc[$docNo][] = self::candidate($cashRow, $id, (int) $year, $ctx->supplierId);
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT);
        // Doklad převedený dřív jako neuhrazený: spárováním se stane uhrazeným. Koncept
        // k ruční kontrole zůstává konceptem, jeho stav určí účetní. Převod zakládá jen
        // běžné faktury (document_kind = 'invoice'), DDKP se sem dostat nemůže.
        $markPurchasePaid = $pdo->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
              WHERE id = ? AND supplier_id = ? AND document_kind = 'invoice' AND status IN ('received', 'booked')"
        );
        $markIssuedPaid = $pdo->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?), paid_total = total_with_vat
              WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')"
        );
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashMulti = [];
        $movements = $this->bankMovements($ctx->supplierId);
        $issuedPayments = new IssuedPaymentRecorder($this->db);
        $matchTx = $pdo->prepare('SELECT bank_transaction_id FROM payment_matches WHERE id = ? AND supplier_id = ? AND invoice_id = ?');
        $sameMatch = $pdo->prepare('SELECT MIN(id) FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND (invoice_id = ? OR purchase_invoice_id = ?)');

        foreach ([['PFaktury', $ctx->purchaseInvoices, false], ['VFaktury', $ctx->issuedInvoices, true]] as [$table, $docs, $isIssued]) {
            foreach ($ctx->backup->rowsAcrossYears($table) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $settledBy = trim((string) ($r['UDoklad'] ?? ''));
                $docId = $docs[$year . '|' . $docNo] ?? null;
                if ($year === null || $docId === null || $settledBy === '') {
                    continue;
                }
                $mapKey = ($isIssued ? 'v' : 'p') . '|' . $year . '|' . $docNo;
                $bankCandidates = $bankByDoc[$settledBy] ?? [];
                $cashCandidates = $cashByDoc[$settledBy] ?? [];
                if (isset($existing[$mapKey])) {
                    // Dřívější běh úhradu spároval, ale evidovanou platbu vydané faktury nezaložil.
                    // Cíl mapy je pokladní doklad (je mezi kandidáty čísla úhrady), nebo párování banky.
                    if ($isIssued) {
                        $target = (int) $existing[$mapKey];
                        if (in_array($target, array_column($cashCandidates, 'id'), true)) {
                            $this->recordIssuedCash($ctx, $issuedPayments, $docId, $target, $cashCandidates, $settledBy);
                        } else {
                            $matchTx->execute([$target, $ctx->supplierId, $docId]);
                            $txOf = $matchTx->fetchColumn();
                            if ($txOf !== false) {
                                $this->recordIssuedBank($ctx, $issuedPayments, $docId, (int) $txOf, $movements, $settledBy);
                            }
                        }
                    }
                    $p->count(self::STEP_PAYMENTS, 'existing');
                    continue;
                }
                $paidAt = InvoiceImporter::date($r, ['Uhrazeno']);
                $docTotal = $this->documentTotal($ctx->supplierId, $isIssued, $docId);
                $txId = self::choosePayment($bankCandidates, $year, $docTotal, $paidAt);
                if ($txId !== null) {
                    $txAmount = 0.0;
                    foreach ($bankCandidates as $c) {
                        $txAmount = $c['id'] === $txId ? abs($c['amount']) : $txAmount;
                    }
                    // Částka párování je v měně pohybu; kandidát nese částku v Kč.
                    $matched = min($txAmount, abs($docTotal)) ?: $txAmount;
                    $movement = $movements[$txId] ?? null;
                    if ($movement !== null && $movement['currency'] !== 'CZK' && $txAmount >= 0.01) {
                        $matched = abs($movement['amount']) * min(1.0, $matched / $txAmount);
                    }
                    // Pohyb s dokladem spárovaný mezitím v MyÚčtu se nezdvojí.
                    $sameMatch->execute([$ctx->supplierId, $txId, $isIssued ? $docId : 0, $isIssued ? 0 : $docId]);
                    $matchId = (int) $sameMatch->fetchColumn();
                    if ($matchId === 0) {
                        $insertMatch->execute([
                            $ctx->supplierId,
                            $txId,
                            $isIssued ? $docId : null,
                            $isIssued ? null : $docId,
                            number_format($matched, 2, '.', ''),
                            $ctx->userId > 0 ? $ctx->userId : null,
                        ]);
                        $matchId = (int) $pdo->lastInsertId();
                    }
                    $markTx->execute([$ctx->userId > 0 ? $ctx->userId : null, $isIssued ? $docId : null, $txId, $ctx->supplierId]);
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedBank($ctx, $issuedPayments, $docId, $txId, $movements, $settledBy);
                    }
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashId = self::choosePayment($cashCandidates, $year, $docTotal, $paidAt);
                if ($cashId !== null) {
                    // Pokladní doklad nese vazbu jen na jeden doklad. Hradí-li víc faktur, zůstane
                    // vazba na první a další ji nepřepíše; faktury jsou uhrazené podle Money.
                    $cashLink->execute([$cashId, $ctx->supplierId]);
                    $linked = $cashLink->fetch(PDO::FETCH_ASSOC) ?: [];
                    $current = $isIssued ? ($linked['invoice_id'] ?? null) : ($linked['purchase_invoice_id'] ?? null);
                    if (($linked['invoice_id'] ?? null) === null && ($linked['purchase_invoice_id'] ?? null) === null) {
                        $pdo->prepare(
                            'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                            . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                        )->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    } elseif ($current === null || (int) $current !== $docId) {
                        $cashMulti[$settledBy] = true;
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    if (!$isIssued) {
                        $pdo->prepare("UPDATE purchase_invoices SET payment_method = 'cash' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $ctx->supplierId]);
                    }
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedCash($ctx, $issuedPayments, $docId, $cashId, $cashCandidates, $settledBy);
                    }
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                if ($bankCandidates !== [] || $cashCandidates !== []) {
                    $p->count(self::STEP_PAYMENTS, 'ambiguous');
                    $p->warn(self::STEP_PAYMENTS, 'payment_ambiguous', "Úhradu {$settledBy} faktury {$docNo} nejde jednoznačně určit — v převedené bance nebo pokladně je víc dokladů se stejným číslem, částkou i datem. Spárujte ji ručně.", ['document_no' => $docNo]);
                    continue;
                }
                $p->count(self::STEP_PAYMENTS, 'not_found');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$settledBy} faktury {$docNo} v převedené bance ani pokladně není.", ['document_no' => $docNo]);
            }
        }
        $this->repairForeignMatchAmounts($ctx, $movements);
        (new PairingSymbolMatcher($this->db, $this->map, $issuedPayments))->match($ctx, $movements);
        (new AdvanceLinker($this->db))->link($ctx);
        $this->markBookedWithoutInvoice($ctx);
        if ($cashMulti !== []) {
            $p->info(self::STEP_PAYMENTS, 'cash_multi_invoice', count($cashMulti) . ' pokladních dokladů hradí víc faktur; pokladní doklad ukazuje vazbu jen na první, faktury jsou uhrazené podle Money.',
                ['documents' => array_slice(array_keys($cashMulti), 0, 50)]);
        }
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Bankovní pohyby firmy jedním dotazem: id => částka v měně pohybu, měna, datum
     * a částka v Kč (u pohybu v cizí měně z Money, jinak částka sama).
     *
     * @return array<int,array{amount:float,currency:string,date:string,czk:float}>
     */
    private function bankMovements(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.amount, t.currency, DATE(t.posted_at) AS posted_on
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = (float) $r['amount'];
            $out[(int) $r['id']] = ['amount' => $amount, 'currency' => (string) $r['currency'], 'date' => (string) $r['posted_on'], 'czk' => $amount];
        }
        return $out;
    }

    /**
     * Párování úhrady z účtu v cizí měně, které dřívější verze převodu zapsala s částkou
     * v Kč (částka párování je v měně pohybu): částka vyšší než celý pohyb se přepočte
     * kurzem pohybu z Money. Jen párování vzniklá převodem (mapa úhrad), párování zadané
     * v MyÚčtu se nemění.
     *
     * @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements
     */
    private function repairForeignMatchAmounts(ImportContext $ctx, array $movements): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT pm.id, pm.bank_transaction_id, pm.amount
               FROM payment_matches pm
               JOIN money_s3_import_map m ON m.supplier_id = pm.supplier_id AND m.kind = ? AND m.target_id = pm.id
              WHERE pm.supplier_id = ?"
        );
        $stmt->execute([MoneyS3ImportRepository::KIND_PAYMENT, $ctx->supplierId]);
        $update = $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE id = ? AND supplier_id = ? AND amount = ?');
        $fixed = 0;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tx = (int) $r['bank_transaction_id'];
            $m = $movements[$tx] ?? null;
            $czk = abs($ctx->bankTransactionCzk[$tx] ?? 0.0);
            $amount = (float) $r['amount'];
            if ($m === null || $m['currency'] === 'CZK' || $czk < 0.01 || $amount <= abs($m['amount']) + 0.01) {
                continue;
            }
            $update->execute([number_format(abs($m['amount']) * min(1.0, $amount / $czk), 2, '.', ''), (int) $r['id'], $ctx->supplierId, $r['amount']]);
            $fixed += $update->rowCount();
        }
        if ($fixed > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'foreign_match_amount_fixed', $fixed);
        }
    }

    /** @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements */
    private function recordIssuedBank(ImportContext $ctx, IssuedPaymentRecorder $payments, int $invoiceId, int $txId, array $movements, string $paymentNo): void
    {
        $m = $movements[$txId] ?? null;
        if ($m === null) {
            return;
        }
        $czk = $m['currency'] === 'CZK' ? $m['amount'] : ($ctx->bankTransactionCzk[$txId] ?? null);
        $amount = $payments->toInvoiceCurrency($ctx->supplierId, $invoiceId, (float) ($czk ?? 0.0), $m['currency'], $m['amount']);
        if ($amount !== null) {
            $payments->record($ctx, $invoiceId, 'bank', $txId, null, $amount, $m['date'], $paymentNo);
        }
    }

    /** @param list<array{id:int,year:int,amount:float,date:?string}> $candidates */
    private function recordIssuedCash(ImportContext $ctx, IssuedPaymentRecorder $payments, int $invoiceId, int $cashId, array $candidates, string $paymentNo): void
    {
        foreach ($candidates as $c) {
            if ($c['id'] === $cashId && $c['date'] !== null) {
                $amount = $payments->toInvoiceCurrency($ctx->supplierId, $invoiceId, $c['amount']);
                if ($amount !== null) {
                    $payments->record($ctx, $invoiceId, 'cash', null, $cashId, $amount, $c['date'], $paymentNo);
                }
                return;
            }
        }
    }

    /**
     * Pohyby, které Money zaúčtovalo bez faktury (poplatky, převody mezi vlastními účty,
     * mzdy, odvody, ostatní závazky), jsou vyřízené - v MyÚčtu pro ně faktura není a výpis
     * by jinak trvale svítil jako nedopárovaný. Označí se jako ignorované s poznámkou; jen
     * pohyby převodu, které mají zápis v deníku a zůstaly nespárované. Uživatel to u pohybu
     * může vrátit.
     */
    private function markBookedWithoutInvoice(ImportContext $ctx): void
    {
        $marked = $this->entries->markBankBookedWithoutDocument(
            $ctx->supplierId,
            $ctx->userId > 0 ? $ctx->userId : null,
            array_map('intval', $ctx->bankTransactions),
            'money_s3_booked',
            'Zaúčtováno v Money S3 bez dokladu (převod z Money S3).',
            "EXISTS (SELECT 1 FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id)",
        );
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_invoice', $marked);
        }
    }

    /**
     * Zápisy deníku roku převedené z Money: typ zdroje → číslo dokladu → id zápisů
     * (v pořadí data). Staví se z mapy převodu, takže funguje i pro zápisy z dřívějšího
     * běhu. Klíč zápisu v mapě je `rok|zdroj|číslo|datum`.
     *
     * @return array<string,array<string,list<array{date:string,id:int}>>>
     */
    private function journalIndex(int $supplierId, int $year): array
    {
        $index = [];
        foreach ($this->map->all($supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|') as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) < 4 || (int) $parts[0] !== $year) {
                continue; // otevírací zápis "rok|XP"
            }
            [, $source, $docNo, $date] = $parts;
            if ($docNo === '') {
                continue;
            }
            $index[Ms3Journal::sourceType($source)][$docNo][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = ['date' => $date, 'id' => $entryId];
        }
        foreach ($index as &$docs) {
            foreach ($docs as &$entries) {
                ksort($entries);
                $entries = array_values($entries);
            }
        }
        unset($docs, $entries);
        return $index;
    }

    private function documentDate(int $supplierId, string $docType, int $docId): ?string
    {
        $stmt = $this->db->pdo()->prepare($docType === 'bank'
            ? 'SELECT DATE(t.posted_at) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE t.id = ? AND s.supplier_id = ?'
            : 'SELECT issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$docId, $supplierId]);
        $date = $stmt->fetchColumn();
        return is_string($date) ? substr($date, 0, 10) : null;
    }

    /**
     * Doklady, u kterých převod zápis v deníku Money nečeká: koncept k ruční kontrole
     * a zálohová faktura, kterou Money neúčtuje (DPH nese daňový doklad k platbě).
     *
     * @return array<int,true>
     */
    private function draftIds(string $table, int $supplierId): array
    {
        $advance = $table === 'invoices' ? "invoice_type = 'proforma'" : "document_kind = 'advance'";
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND (status = 'draft' OR {$advance})");
        $stmt->execute([$supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Celkem dokladu v Kč - úhrady se porovnávají v Kč, doklad v cizí měně se přepočte kurzem dokladu. */
    private function documentTotal(int $supplierId, bool $issued, int $id): float
    {
        $total = ForeignCurrencyTakeover::homeAmountSql('total_with_vat', 'exchange_rate');
        $stmt = $this->db->pdo()->prepare(
            $issued
                ? "SELECT {$total} FROM invoices WHERE id = ? AND supplier_id = ?"
                : "SELECT {$total} FROM purchase_invoices WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Pohyb, kterým byla faktura uhrazena, z dokladů se stejným číslem. Uvažují se
     * doklady z roku faktury a z následujícího (faktura z prosince placená v lednu), jinak
     * jen jediný existující. Rozhoduje shoda částky s fakturou, pak datum úhrady z Money,
     * nakonec stejný rok. Dva stejně dobré kandidáty nejde rozlišit — null.
     *
     * @param list<array{id:int,year:int,amount:float,date:?string}> $candidates
     */
    public static function choosePayment(array $candidates, int $year, float $docTotal, ?string $paidAt): ?int
    {
        $near = array_values(array_filter($candidates, static fn (array $c): bool => $c['year'] === $year || $c['year'] === $year + 1));
        if ($near === [] && count($candidates) === 1) {
            $near = $candidates;
        }
        $best = null;
        $bestScore = -1;
        $tie = false;
        foreach ($near as $c) {
            $score = (ReconciliationTolerance::sameCent(abs($c['amount']), abs($docTotal)) ? 8 : 0)
                + ($paidAt !== null && $c['date'] === $paidAt ? 4 : 0)
                + ($c['year'] === $year ? 2 : 1);
            if ($score > $bestScore) {
                [$best, $bestScore, $tie] = [$c['id'], $score, false];
            } elseif ($score === $bestScore) {
                $tie = true;
            }
        }
        return $tie ? null : $best;
    }

    /** @return array{id:int,year:int,amount:float,date:?string} */
    private static function candidate(\PDOStatement $row, int $id, int $year, int $supplierId): array
    {
        $row->execute([$id, $supplierId]);
        $r = $row->fetch(PDO::FETCH_NUM) ?: [0, null];
        return ['id' => $id, 'year' => $year, 'amount' => (float) $r[0], 'date' => $r[1] !== null ? substr((string) $r[1], 0, 10) : null];
    }
}
