<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
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
 * Interní doklady se stejným symbolem (kurzový rozdíl, zápočet) se na faktury nenavazují:
 * vazba ručního zápisu na doklad se počítá jako jeho zaúčtování (kontrola K4 převodu,
 * Doúčtování dokladů) a kurzový zisk by u faktury s platbou zastropenou na `paid_total`
 * otevřel rozdíl, který hlavní kniha nemá.
 *
 * Opakovaný převod nic nezdvojí: pohyb už spárovaný s fakturou se přeskočí a pokladní
 * doklad s vazbou se nepřepisuje.
 */
final class PairingSymbolMatcher
{
    private const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'Castka', 'UcMD', 'UcD', 'ParSym', 'ParICO'];

    public function __construct(
        private readonly Connection $db,
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

        foreach ($groups as $gk => $g) {
            $docs = array_filter($g['docs'], static fn (array $d, string $ref): bool => $best[$ref][0] === $gk && abs($d['net']) >= 0.01, ARRAY_FILTER_USE_BOTH);
            if ($docs === []) {
                continue;
            }
            // Směr předpisu určí největší doklad skupiny; doklady opačného směru (dobropisy) se neplatí.
            uasort($docs, static fn (array $a, array $b): int => abs($b['net']) <=> abs($a['net']));
            $sign = reset($docs)['net'] > 0 ? 1 : -1;
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
                if ($pay['ico'] !== '' && $g['ico'] !== '' && $pay['ico'] !== $g['ico']) {
                    continue;
                }
                $isBank = $pay['zdroj'] === 'BK';
                $ids = ($isBank ? $bankByDoc : $cashByDoc)[$pay['year'] . '|' . $pay['doc']] ?? [];
                if (count($ids) > 1) {
                    $ids = array_values(array_filter($ids, static fn (int $id): bool => ($isBank ? ($movements[$id]['date'] ?? null) : ($state['cash'][$id]['date'] ?? null)) === $pay['date']));
                }
                if (count($ids) !== 1) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, $ids === [] ? 'paired_movement_missing' : 'paired_ambiguous');
                    continue;
                }
                $movementId = $ids[0];
                $available = abs($pay['net']);
                $m = null;
                $txCzk = 0.0;
                if ($isBank) {
                    $m = $movements[$movementId] ?? null;
                    if ($m === null) {
                        continue;
                    }
                    $txCzk = $m['currency'] === 'CZK' ? abs($m['amount']) : abs($ctx->bankTransactionCzk[$movementId] ?? 0.0);
                    if ($txCzk < 0.01) {
                        continue;
                    }
                    $available = min($available, $txCzk - ($state['txUsed'][$movementId] ?? 0.0));
                } elseif (!isset($state['cash'][$movementId])) {
                    continue;
                }
                foreach ($docs as $ref => $d) {
                    if ($available < 0.01) {
                        break;
                    }
                    $linkKey = ($isBank ? 'b' : 'c') . $movementId;
                    if ($capacity[$ref] < 0.01 || isset($state['linked'][$ref][$linkKey])) {
                        continue;
                    }
                    $part = round(min($capacity[$ref], $available), 2);
                    $isIssued = $d['type'] === 'invoice';
                    if ($m !== null) {
                        $txAmount = $m['currency'] === 'CZK' ? $part : round($part * abs($m['amount']) / $txCzk, 2);
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
                    $capacity[$ref] -= $part;
                    $available -= $part;
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
                default => null,
            };
            if ($doc === null || ($doc[0] !== 'payment' && $doc[1] === null)) {
                continue;
            }
            $ico = trim((string) ($r['ParICO'] ?? ''));
            foreach ([[$effect['debit'], $effect['amount']], [$effect['credit'], -$effect['amount']]] as [$account, $delta]) {
                if (preg_match('/^3[12]/', $account) !== 1) {
                    continue;
                }
                $gk = $sym . '|' . $account;
                $groups[$gk] ??= ['ico' => '', 'docs' => [], 'pay' => []];
                if ($doc[0] === 'payment') {
                    $pk = $year . '|' . $zdroj . '|' . $docNo . '|' . $date;
                    $groups[$gk]['pay'][$pk] ??= ['zdroj' => $zdroj, 'year' => $year, 'doc' => $docNo, 'date' => $date, 'net' => 0.0, 'ico' => $ico];
                    $groups[$gk]['pay'][$pk]['net'] += $delta;
                    continue;
                }
                $ref = $doc[0] . ':' . $doc[1];
                $groups[$gk]['docs'][$ref] ??= ['type' => $doc[0], 'id' => (int) $doc[1], 'net' => 0.0, 'date' => $date];
                $groups[$gk]['docs'][$ref]['net'] += $delta;
                if ($groups[$gk]['ico'] === '' && $ico !== '') {
                    $groups[$gk]['ico'] = $ico;
                }
            }
        }
        return array_filter($groups, static fn (array $g): bool => $g['docs'] !== [] && $g['pay'] !== []);
    }

    /**
     * Už spárované úhrady (v Kč) po dokladech a pohybech a pokladní doklady firmy - jedním
     * dotazem na tabulku.
     *
     * @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements
     * @return array{allocated:array<string,float>,linked:array<string,array<string,true>>,txUsed:array<int,float>,cash:array<int,array{date:string,invoice_id:?int,purchase_invoice_id:?int}>}
     */
    private function state(ImportContext $ctx, array $movements): array
    {
        $pdo = $this->db->pdo();
        $state = ['allocated' => [], 'linked' => [], 'txUsed' => [], 'cash' => []];

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
