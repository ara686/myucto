<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Úhrady podle párovacího symbolu deníku Money (`UcDenik.ParSym`).
 *
 * Saldokonto Money stojí na párovacím symbolu řádků deníku: předpis faktury i každá
 * úhrada (banka, pokladna) nesou na saldokontním účtu stejný symbol. Faktura sama drží
 * v `UDoklad` jen POSLEDNÍ doklad, který ji vyrovnal - u faktury placené po částech jen
 * poslední splátku, u faktury v cizí měně často jen interní doklad s kurzovým rozdílem.
 * Párování jen podle `UDoklad` ({@see DocumentLinker::matchPayments()}) proto nechalo
 * dřívější úhrady nespárované a saldokonto drželo uhrazené faktury jako otevřené.
 *
 * Tady se k převedeným fakturám dopárují bankovní a pokladní úhrady se stejným symbolem
 * na stejném účtu (analytice) jako předpis faktury, a to do výše předpisu snížené o už
 * spárované úhrady. Patří-li k symbolu víc faktur, rozdělí se úhrady podle data. Úhrada
 * opačným směrem (vratka přeplatku, proplacení dobropisu) se nepáruje - dobropis vyrovnává
 * jeho stav z Money. Vydaná faktura dostane i evidovanou platbu ({@see IssuedPaymentRecorder}).
 *
 * Dobropis se stejným symbolem jako jediná faktura skupiny se proti ní započte (vazba na
 * opravovanou fakturu a `credit_note_offsets`) v rozsahu, který nepokryla jeho vratka.
 * Interní doklad (zápočet s dokladem druhé strany, odpis), který snižuje saldo jediné
 * faktury skupiny, se na ni naváže jako ruční zápis; kurzový zisk, který saldo zvyšuje,
 * ne - u faktury s platbou zastropenou na `paid_total` by otevřel rozdíl, který hlavní
 * kniha nemá.
 *
 * Opakovaný převod nic nezdvojí: pohyb už spárovaný s fakturou se přeskočí, pokladní
 * doklad s vazbou se nepřepisuje, dobropis se započte a ruční zápis naváže jen jednou.
 */
final class PairingSymbolMatcher
{
    private const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'Castka', 'UcMD', 'UcD', 'ParSym', 'ParICO', 'CastkaCM', 'Mena'];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly IssuedPaymentRecorder $issuedPayments,
    ) {}

    /** @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements */
    public function match(ImportContext $ctx, array $movements): void
    {
        $groups = $this->groups($ctx);
        if ($groups === []) {
            return;
        }
        // Faktura se páruje jen ve skupině, kde má největší předpis (konečná faktura má
        // se stejným symbolem i odpočet zálohy na 324/314).
        $best = [];
        foreach ($groups as $gk => $g) {
            foreach ($g['docs'] as $ref => $d) {
                if (!isset($best[$ref]) || abs($d['net']) > $best[$ref][1]) {
                    $best[$ref] = [$gk, abs($d['net'])];
                }
            }
        }

        $state = $this->state($ctx, $movements);
        $bankByDoc = self::byDocument($ctx->bankTransactions);
        $cashByDoc = self::byDocument($ctx->cashDocuments);
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        // Pohyb, který krok dřív označil jako zaúčtovaný bez dokladu, je teď úhradou faktury.
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.ignore_note = IF(t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked', NULL, t.ignore_note),
                    t.match_reason = IF(t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked', NULL, t.match_reason),
                    t.match_status = 'manual', t.matched_at = COALESCE(t.matched_at, NOW()), t.matched_by = COALESCE(t.matched_by, ?),
                    t.matched_invoice_id = COALESCE(t.matched_invoice_id, ?)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        $manual = [];
        $cashDates = array_map(static fn (array $c): string => $c['date'], $state['cash']);
        // Pohyb úhrady podle čísla dokladu a roku; stejné číslo na dvou účtech rozliší datum.
        $resolve = static function (array $pay) use ($bankByDoc, $cashByDoc, $movements, $cashDates): int|string {
            $isBank = $pay['zdroj'] === 'BK';
            $ids = ($isBank ? $bankByDoc : $cashByDoc)[$pay['year'] . '|' . $pay['doc']] ?? [];
            if (count($ids) > 1) {
                $ids = array_values(array_filter($ids, static fn (int $id): bool => ($isBank ? ($movements[$id]['date'] ?? null) : ($cashDates[$id] ?? null)) === $pay['date']));
            }
            return count($ids) === 1 ? $ids[0] : ($ids === [] ? 'movement_missing' : 'ambiguous');
        };

        foreach ($groups as $gk => $g) {
            $docs = array_filter($g['docs'], static fn (array $d, string $ref): bool => $best[$ref][0] === $gk && abs($d['net']) >= 0.01, ARRAY_FILTER_USE_BOTH);
            if ($docs === []) {
                continue;
            }
            // Směr předpisu určí největší doklad skupiny; doklady opačného směru (dobropisy) se neplatí.
            uasort($docs, static fn (array $a, array $b): int => abs($b['net']) <=> abs($a['net']));
            $sign = reset($docs)['net'] > 0 ? 1 : -1;
            $notes = array_filter($docs, static fn (array $d): bool => ($d['net'] > 0 ? 1 : -1) !== $sign);
            $docs = array_filter($docs, static fn (array $d): bool => ($d['net'] > 0 ? 1 : -1) === $sign);
            uasort($docs, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $capacity = [];
            foreach ($docs as $ref => $d) {
                $capacity[$ref] = abs($d['net']) - ($state['allocated'][$ref] ?? 0.0);
            }
            $payments = $g['pay'];
            uasort($payments, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            foreach ($payments as $pay) {
                if (($pay['net'] > 0 ? 1 : -1) === $sign || abs($pay['net']) < 0.01) {
                    continue; // vratka, proplacení dobropisu
                }
                // Symbol se v Money opakuje (variabilní symbol jiného partnera, jiný rok); nese-li
                // předpis IČO partnera, musí ho nést i úhrada.
                if ($g['ico'] !== '' && $pay['ico'] !== $g['ico']) {
                    continue;
                }
                $isBank = $pay['zdroj'] === 'BK';
                $movementId = $resolve($pay);
                if (is_string($movementId)) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_' . $movementId);
                    continue;
                }
                $available = abs($pay['net']);
                $m = null;
                // Kurz úhrady v cizí měně (Kč za jednotku) z řádku deníku Money (`CastkaCM`).
                $rate = null;
                if ($isBank) {
                    $m = $movements[$movementId] ?? null;
                    if ($m === null) {
                        continue;
                    }
                    $foreign = $m['currency'] !== 'CZK';
                    if ($foreign) {
                        if ($pay['mena'] !== $m['currency'] || abs($pay['fnet']) < 0.01) {
                            $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_currency_unknown');
                            continue;
                        }
                        $rate = abs($pay['net']) / abs($pay['fnet']);
                    }
                    $txCzk = $foreign ? abs($ctx->bankTransactionCzk[$movementId] ?? $m['amount'] * $rate) : abs($m['amount']);
                    $available = min($available, $txCzk - ($state['txUsed'][$movementId] ?? 0.0));
                } elseif (!isset($state['cash'][$movementId])) {
                    continue;
                }
                foreach ($docs as $ref => $d) {
                    if ($available < 0.01) {
                        break;
                    }
                    $linkKey = ($isBank ? 'b' : 'c') . $movementId;
                    if (isset($state['linked'][$ref][$linkKey])) {
                        continue;
                    }
                    $isIssued = $d['type'] === 'invoice';
                    if ($rate !== null && $d['mena'] === $m['currency'] && abs($d['fnet']) >= 0.01) {
                        // Doklad i úhrada v téže cizí měně: páruje se v ní, kurzový rozdíl
                        // mezi předpisem a úhradou v Kč úhradu nekrátí.
                        $txAmount = round(min(abs($d['fnet']) - ($state['allocatedF'][$ref][$m['currency']] ?? 0.0), $available / $rate), 2);
                        if ($txAmount < 0.01) {
                            continue;
                        }
                        $part = round($txAmount * $rate, 2);
                        $state['allocatedF'][$ref][$m['currency']] = ($state['allocatedF'][$ref][$m['currency']] ?? 0.0) + $txAmount;
                    } else {
                        if ($capacity[$ref] < 0.01) {
                            continue;
                        }
                        $part = round(min($capacity[$ref], $available), 2);
                        $txAmount = $rate === null ? $part : round($part / $rate, 2);
                    }
                    if ($m !== null) {
                        $insertMatch->execute([$ctx->supplierId, $movementId, $isIssued ? $d['id'] : null, $isIssued ? null : $d['id'],
                            number_format($txAmount, 2, '.', ''), $userId]);
                        $markTx->execute([$userId, $isIssued ? $d['id'] : null, $movementId, $ctx->supplierId]);
                        $state['txUsed'][$movementId] = ($state['txUsed'][$movementId] ?? 0.0) + $part;
                        if ($isIssued) {
                            $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $part, $m['currency'], $txAmount);
                            if ($amount !== null) {
                                $this->issuedPayments->record($ctx, $d['id'], 'bank', $movementId, null, $amount, $m['date'], $pay['doc']);
                            }
                        }
                        $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_bank');
                    } else {
                        $cash = $state['cash'][$movementId];
                        if ($cash['invoice_id'] === null && $cash['purchase_invoice_id'] === null) {
                            $pdo->prepare('UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                                . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ? AND invoice_id IS NULL AND purchase_invoice_id IS NULL')
                                ->execute([$d['id'], $isIssued ? 'invoice_payment' : 'purchase_payment', $movementId, $ctx->supplierId]);
                            $state['cash'][$movementId][$isIssued ? 'invoice_id' : 'purchase_invoice_id'] = $d['id'];
                        } elseif (!$isIssued) {
                            // Pokladní doklad nese vazbu jen na jeden doklad; přijatou fakturu
                            // bez vazby saldokonto nespáruje, uhrazená zůstává podle Money.
                            continue;
                        }
                        if ($isIssued) {
                            $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $part);
                            if ($amount !== null) {
                                $this->issuedPayments->record($ctx, $d['id'], 'cash', null, $movementId, $amount, $cash['date'], $pay['doc']);
                            }
                        }
                        $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_cash');
                    }
                    $state['linked'][$ref][$linkKey] = true;
                    $capacity[$ref] = max(0.0, $capacity[$ref] - $part);
                    $available -= $part;
                }
            }

            // Dobropis vyrovnaný proti faktuře se stejným symbolem: co z dobropisu nepokryla
            // jeho vlastní vratka, započetl Money proti faktuře.
            $invoices = array_filter($docs, static fn (array $d): bool => ($state['info'][$d['type']][$d['id']]['kind'] ?? '') === 'invoice');
            if ($notes !== [] && count($invoices) === 1) {
                $invoiceRef = (string) array_key_first($invoices);
                $refunds = 0.0;
                foreach ($g['pay'] as $pay) {
                    if (($pay['net'] > 0 ? 1 : -1) === $sign && abs($pay['net']) >= 0.01 && ($g['ico'] === '' || $pay['ico'] === $g['ico'])) {
                        $movementId = $resolve($pay);
                        $linkKey = ($pay['zdroj'] === 'BK' ? 'b' : 'c') . (is_int($movementId) ? $movementId : 0);
                        $linkedToNote = false;
                        foreach ($notes as $noteRef => $_) {
                            $linkedToNote = $linkedToNote || isset($state['linked'][$noteRef][$linkKey]);
                        }
                        $refunds += $linkedToNote ? 0.0 : abs($pay['net']);
                    }
                }
                uasort($notes, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
                foreach ($notes as $noteRef => $note) {
                    if (($state['info'][$note['type']][$note['id']]['kind'] ?? '') !== 'credit_note' || $note['type'] !== $invoices[$invoiceRef]['type']) {
                        continue;
                    }
                    $left = abs($note['net']) - ($state['allocated'][$noteRef] ?? 0.0);
                    $fromRefund = min($left, $refunds);
                    $refunds -= $fromRefund;
                    $offset = round(min($left - $fromRefund, $capacity[$invoiceRef]), 2);
                    if ($offset >= 0.01 && $this->offsetCreditNote($ctx, $invoices[$invoiceRef], $note, $offset, $state)) {
                        $capacity[$invoiceRef] -= $offset;
                        $p->count(DocumentLinker::STEP_PAYMENTS, 'credit_note_offset');
                    }
                }
            }

            // Interní doklad, který vyrovnává jedinou fakturu skupiny (zápočet, odpis) -
            // kurzový zisk, který pohledávku či závazek zvyšuje, se nenavazuje.
            if (count($g['docs']) === 1 && $notes === [] && $g['manual'] !== []) {
                $d = reset($docs);
                foreach ($g['manual'] as $entryKey => $net) {
                    if (abs($net) >= 0.01 && ($net > 0 ? 1 : -1) !== $sign) {
                        foreach ($state['entries'][$entryKey] ?? [] as $entryId) {
                            $manual[$entryId][$d['type']][$d['id']] = true;
                        }
                    }
                }
            }
        }
        $this->linkManualEntries($ctx, $manual, $state);
    }

    /**
     * Zápočet dobropisu proti faktuře se stejným párovacím symbolem: dobropis dostane
     * vazbu na opravovanou fakturu a zápočet (`credit_note_offsets`) jako po zápočtu
     * v MyÚčtu, vydaná faktura i evidovanou platbu se zdrojem `credit_note`. Stav dokladů
     * vede převod podle Money. Doklady v různé měně se nezapočítávají.
     *
     * @param array{type:string,id:int,net:float,date:string} $invoice
     * @param array{type:string,id:int,net:float,date:string} $note
     * @param array<string,mixed> $state
     */
    private function offsetCreditNote(ImportContext $ctx, array $invoice, array $note, float $czk, array $state): bool
    {
        $type = $invoice['type'];
        $invoiceInfo = $state['info'][$type][$invoice['id']];
        $noteInfo = $state['info'][$type][$note['id']];
        if ($invoiceInfo['currency'] !== $noteInfo['currency']) {
            return false;
        }
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE supplier_id = ? AND doc_type = ? AND credit_note_id = ?');
        $exists->execute([$ctx->supplierId, $type, $note['id']]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $amount = $noteInfo['currency'] === 'CZK' ? $czk : ($noteInfo['rate'] !== null ? round($czk / $noteInfo['rate'], 2) : 0.0);
        if ($amount < 0.01) {
            return false;
        }
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $parent = $type === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
        $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
            ->execute([$invoice['id'], $note['id'], $ctx->supplierId]);
        $paymentId = $type === 'invoice'
            ? $this->issuedPayments->record($ctx, $invoice['id'], 'credit_note', null, null, $amount, $note['date'], $noteInfo['number'])
            : null;
        $pdo->prepare(
            'INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $type, $invoice['id'], $note['id'], number_format($amount, 2, '.', ''), $note['date'], $paymentId,
            $ctx->userId > 0 ? $ctx->userId : null]);
        return true;
    }

    /**
     * Ruční zápis interního dokladu navázaný na fakturu, kterou vyrovnává
     * ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}). Jen k faktuře s vlastním
     * zaúčtovaným zápisem a jen zápis, který žádnou jinou fakturu téhož druhu nevyrovnává
     * (vazba nenese rozpad částky).
     *
     * @param array<int,array<string,array<int,true>>> $manual zápis => druh dokladu => doklady
     * @param array<string,mixed> $state
     */
    private function linkManualEntries(ImportContext $ctx, array $manual, array $state): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($manual as $entryId => $byType) {
            foreach ($byType as $type => $docIds) {
                $docId = (int) array_key_first($docIds);
                $already = $state['manualLinks'][$entryId][$type] ?? [];
                if (count($docIds) !== 1 || !isset($state['booked'][$type][$docId]) || $already !== []) {
                    continue;
                }
                $insert->execute([$ctx->supplierId, $entryId, $type, $docId, 'Vyrovnání interním dokladem (převzato z Money S3)', $ctx->userId > 0 ? $ctx->userId : null]);
                if ($insert->rowCount() > 0) {
                    $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'internal_settlement_linked');
                }
            }
        }
    }

    /**
     * Řádky deníku převáděných roků s párovacím symbolem na saldokontních účtech (31x, 32x),
     * seskupené podle symbolu a účtu: předpisy převedených faktur a úhrady banky a pokladny.
     *
     * @return array<string,array{ico:string,docs:array<string,array{type:string,id:int,net:float,date:string}>,pay:array<string,array{zdroj:string,year:int,doc:string,date:string,net:float,ico:string}>}>
     */
    private function groups(ImportContext $ctx): array
    {
        $dirs = [];
        foreach ($ctx->dirYears as $dir => $year) {
            if (isset($ctx->periods[$year])) {
                $dirs[] = $dir;
            }
        }
        if ($dirs === []) {
            return [];
        }
        $groups = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', self::FIELDS, $dirs) as $r) {
            $sym = trim((string) ($r['ParSym'] ?? ''));
            if ($sym === '' || Ms3Journal::isOpening($r) || Ms3Journal::isYearEndClosing($r)) {
                continue;
            }
            $effect = Ms3Journal::effect($r);
            $year = $ctx->yearOf($r);
            $zdroj = strtoupper(trim((string) ($r['Zdroj'] ?? '')));
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $date = substr((string) ($r['Datum'] ?? ''), 0, 10);
            if ($effect === null || $year === null || $docNo === '' || $date === '') {
                continue;
            }
            $doc = match ($zdroj) {
                'FV' => ['invoice', $ctx->issuedInvoices[$year . '|' . $docNo] ?? null],
                'KP' => ['invoice', $ctx->otherReceivables[$year . '|' . $docNo] ?? null],
                'FP' => ['purchase_invoice', $ctx->purchaseInvoices[$year . '|' . $docNo] ?? null],
                'BK', 'PK' => ['payment', null],
                default => Ms3Journal::sourceType($zdroj) === 'manual' ? ['manual', null] : null,
            };
            if ($doc === null || (!in_array($doc[0], ['payment', 'manual'], true) && $doc[1] === null)) {
                continue;
            }
            $ico = trim((string) ($r['ParICO'] ?? ''));
            $mena = mb_strtoupper(trim((string) ($r['Mena'] ?? '')));
            $mena = in_array($mena, ['', 'KČ', 'KC', 'CZK'], true) ? 'CZK' : $mena;
            $foreign = $mena === 'CZK' ? 0.0 : abs((float) ($r['CastkaCM'] ?? 0));
            foreach ([[$effect['debit'], $effect['amount']], [$effect['credit'], -$effect['amount']]] as [$account, $delta]) {
                if (preg_match('/^3[12]/', $account) !== 1) {
                    continue;
                }
                $fdelta = $delta > 0 ? $foreign : -$foreign;
                $gk = $sym . '|' . $account;
                $groups[$gk] ??= ['ico' => '', 'docs' => [], 'pay' => [], 'manual' => []];
                if ($doc[0] === 'manual') {
                    $mk = $year . '|' . trim((string) $r['Zdroj']) . '|' . $docNo . '|' . $date;
                    $groups[$gk]['manual'][$mk] = ($groups[$gk]['manual'][$mk] ?? 0.0) + $delta;
                    continue;
                }
                if ($doc[0] === 'payment') {
                    $pk = $year . '|' . $zdroj . '|' . $docNo . '|' . $date;
                    $groups[$gk]['pay'][$pk] ??= ['zdroj' => $zdroj, 'year' => $year, 'doc' => $docNo, 'date' => $date, 'net' => 0.0, 'fnet' => 0.0, 'mena' => $mena, 'ico' => $ico];
                    $groups[$gk]['pay'][$pk]['net'] += $delta;
                    $groups[$gk]['pay'][$pk]['fnet'] += $fdelta;
                    continue;
                }
                $ref = $doc[0] . ':' . $doc[1];
                $groups[$gk]['docs'][$ref] ??= ['type' => $doc[0], 'id' => (int) $doc[1], 'net' => 0.0, 'fnet' => 0.0, 'mena' => $mena, 'date' => $date];
                $groups[$gk]['docs'][$ref]['net'] += $delta;
                $groups[$gk]['docs'][$ref]['fnet'] += $fdelta;
                if ($groups[$gk]['ico'] === '' && $ico !== '') {
                    $groups[$gk]['ico'] = $ico;
                }
            }
        }
        return array_filter($groups, static fn (array $g): bool => $g['docs'] !== [] && ($g['pay'] !== [] || $g['manual'] !== [] || count($g['docs']) > 1));
    }

    /**
     * Stav firmy, ze kterého párování vychází - jedním dotazem na tabulku: už spárované
     * úhrady (v Kč) po dokladech a pohybech, pokladní doklady, druh a měna dokladů, zápisy
     * interních dokladů z mapy převodu, zaúčtované doklady a vazby ručních zápisů.
     *
     * @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements
     * @return array<string,mixed>
     */
    private function state(ImportContext $ctx, array $movements): array
    {
        $pdo = $this->db->pdo();
        $state = ['allocated' => [], 'allocatedF' => [], 'linked' => [], 'txUsed' => [], 'cash' => [], 'info' => [], 'entries' => [], 'booked' => [], 'manualLinks' => []];

        $pm = $pdo->prepare('SELECT bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches WHERE supplier_id = ?');
        $pm->execute([$ctx->supplierId]);
        while ($r = $pm->fetch(PDO::FETCH_ASSOC)) {
            $tx = (int) $r['bank_transaction_id'];
            $m = $movements[$tx] ?? null;
            $czk = abs((float) $r['amount']);
            // Párování nese částku v měně pohybu, saldokonto Money je v Kč.
            if ($m !== null && $m['currency'] !== 'CZK' && abs($m['amount']) >= 0.01 && isset($ctx->bankTransactionCzk[$tx])) {
                $czk = $czk * abs($ctx->bankTransactionCzk[$tx]) / abs($m['amount']);
            }
            $state['txUsed'][$tx] = ($state['txUsed'][$tx] ?? 0.0) + $czk;
            $ref = $r['invoice_id'] !== null ? 'invoice:' . $r['invoice_id'] : ($r['purchase_invoice_id'] !== null ? 'purchase_invoice:' . $r['purchase_invoice_id'] : null);
            if ($ref !== null) {
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $czk;
                $state['linked'][$ref]['b' . $tx] = true;
                if ($m !== null && $m['currency'] !== 'CZK') {
                    $state['allocatedF'][$ref][$m['currency']] = ($state['allocatedF'][$ref][$m['currency']] ?? 0.0) + abs((float) $r['amount']);
                }
            }
        }

        $cash = $pdo->prepare("SELECT id, issue_date, total_amount, invoice_id, purchase_invoice_id FROM cash_documents WHERE supplier_id = ? AND status <> 'draft'");
        $cash->execute([$ctx->supplierId]);
        while ($r = $cash->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $r['id'];
            $state['cash'][$id] = [
                'date' => substr((string) $r['issue_date'], 0, 10),
                'invoice_id' => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'purchase_invoice_id' => $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null,
            ];
            $ref = $r['invoice_id'] !== null ? 'invoice:' . $r['invoice_id'] : ($r['purchase_invoice_id'] !== null ? 'purchase_invoice:' . $r['purchase_invoice_id'] : null);
            if ($ref !== null) {
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + abs((float) $r['total_amount']);
                $state['linked'][$ref]['c' . $id] = true;
            }
        }

        foreach ([
            'invoice' => 'SELECT d.id, d.invoice_type AS kind, d.exchange_rate, d.varsymbol, cur.code FROM invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
            'purchase_invoice' => 'SELECT d.id, d.document_kind AS kind, d.exchange_rate, d.varsymbol, cur.code FROM purchase_invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
        ] as $type => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ctx->supplierId]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $state['info'][$type][(int) $r['id']] = [
                    'kind' => (string) $r['kind'], 'currency' => (string) $r['code'], 'number' => (string) $r['varsymbol'],
                    'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                ];
            }
        }

        // Zápisy interních dokladů z mapy převodu (klíč `rok|zdroj|číslo|datum`).
        foreach ($this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) >= 4 && Ms3Journal::sourceType($parts[1]) === 'manual') {
                $state['entries'][$parts[0] . '|' . $parts[1] . '|' . $parts[2] . '|' . $parts[3]][] = (int) $entryId;
            }
        }
        $links = $pdo->prepare(
            "SELECT dl.entry_id, dl.doc_type, dl.doc_id, e.source_type
               FROM journal_entry_document_links dl
               JOIN journal_entries e ON e.id = dl.entry_id AND e.supplier_id = dl.supplier_id
              WHERE dl.supplier_id = ? AND dl.doc_type IN ('invoice', 'purchase_invoice')"
        );
        $links->execute([$ctx->supplierId]);
        while ($r = $links->fetch(PDO::FETCH_ASSOC)) {
            if ((string) $r['source_type'] === (string) $r['doc_type']) {
                $state['booked'][(string) $r['doc_type']][(int) $r['doc_id']] = true;
            } elseif ((string) $r['source_type'] === 'manual') {
                $state['manualLinks'][(int) $r['entry_id']][(string) $r['doc_type']][] = (int) $r['doc_id'];
            }
        }
        return $state;
    }

    /**
     * Klíče převodu `rok|číslo[|účet]` → id po `rok|číslo` (stejné číslo dokladu na dvou
     * účtech či pokladnách rozliší datum).
     *
     * @param array<string,int> $map
     * @return array<string,list<int>>
     */
    private static function byDocument(array $map): array
    {
        $out = [];
        foreach ($map as $key => $id) {
            $parts = explode('|', (string) $key, 3);
            $out[$parts[0] . '|' . ($parts[1] ?? '')][] = (int) $id;
        }
        return $out;
    }
}
