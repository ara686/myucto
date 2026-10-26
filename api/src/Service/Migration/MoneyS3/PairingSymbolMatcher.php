<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Vyrovnání dokladů podle párovacího symbolu deníku Money (`UcDenik.ParSym`).
 *
 * Saldokonto Money stojí na párovacím symbolu řádků deníku: předpis faktury, každá
 * úhrada (banka, pokladna) i vyrovnání interním dokladem (zápočet přes 395, odpis,
 * kurzový rozdíl) nesou na saldokontním účtu stejný symbol. Faktura sama drží v `UDoklad`
 * jen POSLEDNÍ doklad, který ji vyrovnal - u faktury placené po částech jen poslední
 * splátku, u faktury v cizí měně často jen interní doklad s kurzovým rozdílem. Párování
 * jen podle `UDoklad` ({@see DocumentLinker::matchPayments()}) proto nechalo dřívější
 * úhrady nespárované a saldokonto drželo uhrazené faktury jako otevřené.
 *
 * Skupina = symbol × účet (analytika) předpisu. Ve skupině se:
 *  - dopárují bankovní a pokladní úhrady do výše předpisu snížené o už evidované úhrady,
 *    u víc faktur podle data; úhrada v cizí měně v měně dokladu (kurz z `CastkaCM`),
 *    takže kurzový rozdíl úhradu nekrátí; vratka ani proplacení dobropisu se nepárují,
 *  - dobropis jediné faktury skupiny započte proti ní (vazba na opravovanou fakturu
 *    a `credit_note_offsets`) v rozsahu, který nepokryla jeho vratka,
 *  - vyrovnání interním dokladem (zápočet přes 395, odpis, kurzový rozdíl) zapíše
 *    ({@see recordInternalSettlements()}): zápis vyrovnávající jediný doklad svého druhu
 *    vazbou ručního zápisu na doklad, zápis vyrovnávající víc dokladů téhož druhu rozpadem
 *    podle řádků deníku jako zápočty proti protiúčtu (`invoice_settlements`, u vydané faktury
 *    i evidovaná platba `settlement`). Započte se jen čistý úbytek salda ze všech interních
 *    dokladů skupiny, kurzový zisk ho snižuje; u dokladu v cizí měně v jeho měně (korunová
 *    částka kurzem dokladu).
 *
 * Úhrady se symbolem, ke kterému převod doklad nemá (Money symbol jednou zapsalo s překlepem),
 * se přiřadí k jediné otevřené faktuře téhož partnera (IČO) na stejném účtu, jejíž symbol se
 * liší nejvýš jedním znakem.
 *
 * Nese-li předpis IČO partnera, musí ho nést i úhrada - symbol se v Money opakuje u jiných
 * partnerů a let.
 *
 * Opakovaný převod nic nepřepisuje ani nezdvojí: pohyb, zápis interního dokladu či dobropis
 * už vázaný k dokladu se přeskočí, pokladní doklad s vazbou se nepřepisuje a úhrady zadané
 * v MyÚčtu po převodu (ruční platby, zápočty) snižují, co zbývá spárovat.
 */
final class PairingSymbolMatcher
{
    private const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'Castka', 'UcMD', 'UcD', 'ParSym', 'ParICO', 'CastkaCM', 'Mena'];

    private ImportContext $ctx;

    /** @var array<int,array{amount:float,currency:string,date:string,czk:float}> */
    private array $movements = [];

    /** @var array<string,mixed> */
    private array $state = [];

    /** @var array<string,float> zbývá vyrovnat v Kč po dokladech */
    private array $capacity = [];

    /** @var array<int,array<string,array<string,array<string,mixed>>>> vyrovnání interním dokladem: zápis => druh => doklad => částka */
    private array $internal = [];

    /** @var array<int,array<string,array<string,true>>> doklady na řádcích zápisu interního dokladu: zápis => druh => doklady */
    private array $entryDocs = [];

    /** @var array<string,list<int>> */
    private array $bankByDoc = [];

    /** @var array<string,list<int>> */
    private array $cashByDoc = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly IssuedPaymentRecorder $issuedPayments,
    ) {}

    /** @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements */
    public function match(ImportContext $ctx, array $movements): void
    {
        $this->ctx = $ctx;
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
        $this->movements = $movements;
        $this->state = $this->state($ctx, $movements);
        $this->bankByDoc = self::byDocument($ctx->bankTransactions);
        $this->cashByDoc = self::byDocument($ctx->cashDocuments);
        $p = $ctx->protocol;
        // Měna dokladu je ta, ve které ho převod založil: doklad v měně, kterou firma v číselníku
        // nemá, je v MyÚčtu v Kč, i když ho deník Money vede ve valutách.
        foreach ($groups as $gk => $g) {
            foreach ($g['docs'] as $ref => $d) {
                if (!isset($this->state['info'][$d['type']][$d['id']])) {
                    // Doklad z mapy převodu v MyÚčtu mezitím smazali.
                    unset($groups[$gk]['docs'][$ref], $best[$ref]);
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'missing_target');
                    continue;
                }
                if ($this->state['info'][$d['type']][$d['id']]['currency'] === 'CZK') {
                    $groups[$gk]['docs'][$ref]['mena'] = 'CZK';
                }
            }
        }

        $open = [];
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
            foreach ($docs as $ref => $d) {
                $this->capacity[$ref] = abs($d['net']) - ($this->state['allocated'][$ref] ?? 0.0);
            }
            $payments = $g['pay'];
            uasort($payments, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            foreach ($payments as $pay) {
                if (($pay['net'] > 0 ? 1 : -1) === $sign || abs($pay['net']) < 0.01) {
                    continue; // vratka, proplacení dobropisu
                }
                if ($g['ico'] !== '' && $pay['ico'] !== $g['ico']) {
                    continue;
                }
                $this->pay($pay, $docs);
            }
            $this->offsetCreditNotes($g, $docs, $notes, $sign);
            $this->settleByInternalDocuments($g, $docs, $sign);
            [$sym, $account] = explode('|', $gk, 2);
            foreach ($docs as $ref => $d) {
                if ($this->capacity[$ref] >= 0.01 && $g['ico'] !== '') {
                    $open[$account . '|' . $g['ico']][$ref] = ['doc' => $d, 'sym' => $sym];
                }
            }
        }

        // Úhrady se symbolem, ke kterému převod doklad nemá: překlep v symbolu u téhož partnera.
        // Opravný převod tohle odhadovat nesmí - pohyb mohl uživatel mezitím spárovat sám.
        foreach ($ctx->options->relinkOnly ? [] : $groups as $gk => $g) {
            if ($g['docs'] !== []) {
                continue;
            }
            [$sym, $account] = explode('|', $gk, 2);
            $issued = str_starts_with($account, '31');
            foreach ($g['pay'] as $pay) {
                if ($pay['ico'] === '' || strlen($sym) < 6 || ($pay['net'] < 0) !== $issued || abs($pay['net']) < 0.01) {
                    continue;
                }
                $partnerOpen = $open[$account . '|' . $pay['ico']] ?? [];
                $candidates = array_filter($partnerOpen, fn (array $o, string $ref): bool =>
                    $this->capacity[$ref] >= abs($pay['net']) - 0.005 && self::typo($o['sym'], $sym), ARRAY_FILTER_USE_BOTH);
                // Měsíční faktury téhož partnera mívají stejnou částku i podobný symbol: víc
                // otevřených dokladů se stejným zůstatkem znamená, že úhradu odhadnout nejde.
                $sameAmount = array_filter($partnerOpen, fn (array $o, string $ref): bool =>
                    abs($this->capacity[$ref] - abs($pay['net'])) < 0.01, ARRAY_FILTER_USE_BOTH);
                if (count($candidates) !== 1 || count($sameAmount) > 1) {
                    continue;
                }
                $ref = (string) array_key_first($candidates);
                if ($this->pay($pay, [$ref => $candidates[$ref]['doc']]) > 0) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_similar_symbol');
                }
            }
        }
        $this->recordInternalSettlements();
    }

    /**
     * Bankovní či pokladní úhrada ze skupiny k dokladům skupiny (podle data, do zbývající
     * částky). Vrací počet vazeb, které vznikly.
     *
     * @param array<string,mixed> $pay
     * @param array<string,array<string,mixed>> $docs
     */
    private function pay(array $pay, array $docs): int
    {
        $ctx = $this->ctx;
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        $isBank = $pay['zdroj'] === 'BK';
        $movementId = $this->resolve($pay);
        if (is_string($movementId)) {
            $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_' . $movementId);
            return 0;
        }
        $available = abs($pay['net']);
        $m = null;
        // Kurz úhrady v cizí měně (Kč za jednotku) z řádku deníku Money (`CastkaCM`).
        $rate = null;
        if ($isBank) {
            $m = $this->movements[$movementId] ?? null;
            if (!DocumentLinker::movementEditable($m, $ctx->options->relinkOnly)) {
                // Pohyb bez převzatého bankovního zápisu, ignorovaný uživatelem, nebo v opravném
                // převodu už spárovaný - jeho párování je rozhodnutí v MyÚčtu.
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_movement_kept');
                return 0;
            }
            $foreign = $m['currency'] !== 'CZK';
            if ($foreign) {
                if ($pay['mena'] !== $m['currency'] || abs($pay['fnet']) < 0.01) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_currency_unknown');
                    return 0;
                }
                $rate = abs($pay['net']) / abs($pay['fnet']);
            }
            $txCzk = $foreign ? abs($ctx->bankTransactionCzk[$movementId] ?? $m['amount'] * $rate) : abs($m['amount']);
            // Nespárovaná část pohybu: v Kč podle evidence párování i podle jeho součtu v měně pohybu.
            $available = min($available, $txCzk - ($this->state['txUsed'][$movementId] ?? 0.0),
                (abs($m['amount']) - $m['matched']) * ($rate ?? 1.0));
        } elseif (!isset($this->state['cash'][$movementId])) {
            return 0;
        }
        $made = 0;
        foreach ($docs as $ref => $d) {
            if ($available < 0.01) {
                break;
            }
            $linkKey = ($isBank ? 'b' : 'c') . $movementId;
            if (isset($this->state['linked'][$ref][$linkKey])) {
                continue;
            }
            $isIssued = $d['type'] === 'invoice';
            if ($rate !== null && $d['mena'] === $m['currency'] && abs($d['fnet']) >= 0.01) {
                // Doklad i úhrada v téže cizí měně: páruje se v ní.
                $txAmount = round(min(abs($d['fnet']) - ($this->state['allocatedF'][$ref][$m['currency']] ?? 0.0), $available / $rate), 2);
                if ($txAmount < 0.01) {
                    continue;
                }
                $part = round($txAmount * $rate, 2);
                $this->state['allocatedF'][$ref][$m['currency']] = ($this->state['allocatedF'][$ref][$m['currency']] ?? 0.0) + $txAmount;
            } else {
                if ($this->capacity[$ref] < 0.01) {
                    continue;
                }
                $part = round(min($this->capacity[$ref], $available), 2);
                $txAmount = $rate === null ? $part : round($part / $rate, 2);
            }
            if ($m !== null) {
                $pdo->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                     VALUES (?, ?, ?, ?, ?, "manual", ?)'
                )->execute([$ctx->supplierId, $movementId, $isIssued ? $d['id'] : null, $isIssued ? null : $d['id'],
                    number_format($txAmount, 2, '.', ''), $userId]);
                // Pohyb, který krok dřív označil jako zaúčtovaný bez dokladu, je teď úhradou faktury.
                $pdo->prepare(
                    "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                        SET t.ignore_note = IF(t.match_status = 'ignored', NULL, t.ignore_note),
                            t.match_reason = IF(t.match_status = 'ignored', NULL, t.match_reason),
                            t.match_status = 'manual', t.matched_at = COALESCE(t.matched_at, NOW()), t.matched_by = COALESCE(t.matched_by, ?),
                            t.matched_invoice_id = COALESCE(t.matched_invoice_id, ?)
                      WHERE t.id = ? AND s.supplier_id = ? AND (t.match_status <> 'ignored' OR t.match_reason = 'money_s3_booked')"
                )->execute([$userId, $isIssued ? $d['id'] : null, $movementId, $ctx->supplierId]);
                $this->state['txUsed'][$movementId] = ($this->state['txUsed'][$movementId] ?? 0.0) + $part;
                $this->movements[$movementId]['matched'] += $txAmount;
                $this->movements[$movementId]['status'] = 'manual';
                $m['matched'] += $txAmount;
                if ($isIssued) {
                    $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $part, $m['currency'], $txAmount);
                    if ($amount !== null) {
                        $this->issuedPayments->record($ctx, $d['id'], 'bank', $movementId, null, $amount, $m['date'], $pay['doc']);
                    }
                }
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_bank');
            } else {
                $cash = $this->state['cash'][$movementId];
                if ($cash['invoice_id'] === null && $cash['purchase_invoice_id'] === null) {
                    $pdo->prepare('UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                        . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ? AND invoice_id IS NULL AND purchase_invoice_id IS NULL')
                        ->execute([$d['id'], $isIssued ? 'invoice_payment' : 'purchase_payment', $movementId, $ctx->supplierId]);
                    $this->state['cash'][$movementId][$isIssued ? 'invoice_id' : 'purchase_invoice_id'] = $d['id'];
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
            $this->state['linked'][$ref][$linkKey] = true;
            $this->capacity[$ref] = max(0.0, $this->capacity[$ref] - $part);
            $available -= $part;
            $made++;
        }
        return $made;
    }

    /**
     * Dobropis vyrovnaný proti faktuře se stejným symbolem: co z dobropisu nepokryla jeho
     * vlastní vratka, započetl Money proti faktuře.
     *
     * @param array<string,mixed> $g
     * @param array<string,array<string,mixed>> $docs
     * @param array<string,array<string,mixed>> $notes
     */
    private function offsetCreditNotes(array $g, array $docs, array $notes, int $sign): void
    {
        $info = $this->state['info'];
        $invoices = array_filter($docs, static fn (array $d): bool => ($info[$d['type']][$d['id']]['kind'] ?? '') === 'invoice');
        if ($notes === [] || count($invoices) !== 1) {
            return;
        }
        $invoiceRef = (string) array_key_first($invoices);
        $refunds = 0.0;
        foreach ($g['pay'] as $pay) {
            if (($pay['net'] > 0 ? 1 : -1) === $sign && abs($pay['net']) >= 0.01 && ($g['ico'] === '' || $pay['ico'] === $g['ico'])) {
                $movementId = $this->resolve($pay);
                $linkKey = ($pay['zdroj'] === 'BK' ? 'b' : 'c') . (is_int($movementId) ? $movementId : 0);
                $linkedToNote = false;
                foreach ($notes as $noteRef => $_) {
                    $linkedToNote = $linkedToNote || isset($this->state['linked'][$noteRef][$linkKey]);
                }
                $refunds += $linkedToNote ? 0.0 : abs($pay['net']);
            }
        }
        uasort($notes, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        foreach ($notes as $noteRef => $note) {
            // Dobropis, nebo storno faktury, které Money vede jako fakturu se zápornou částkou.
            if (!in_array($info[$note['type']][$note['id']]['kind'] ?? '', ['credit_note', 'invoice'], true) || $note['type'] !== $invoices[$invoiceRef]['type']) {
                continue;
            }
            $left = abs($note['net']) - ($this->state['allocated'][$noteRef] ?? 0.0);
            $fromRefund = min($left, $refunds);
            $refunds -= $fromRefund;
            $offset = round(min($left - $fromRefund, $this->capacity[$invoiceRef]), 2);
            if ($offset >= 0.01 && $this->offsetCreditNote($invoices[$invoiceRef], $note, $offset)) {
                $this->capacity[$invoiceRef] -= $offset;
                $this->ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'credit_note_offset');
            }
        }
    }

    /**
     * Zápočet dobropisu proti faktuře se stejným párovacím symbolem: dobropis dostane
     * vazbu na opravovanou fakturu a zápočet (`credit_note_offsets`) jako po zápočtu
     * v MyÚčtu, vydaná faktura i evidovanou platbu se zdrojem `credit_note`. Stav dokladů
     * vede převod podle Money. Doklady v různé měně se nezapočítávají.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $note
     */
    private function offsetCreditNote(array $invoice, array $note, float $czk): bool
    {
        $ctx = $this->ctx;
        $type = $invoice['type'];
        $invoiceInfo = $this->state['info'][$type][$invoice['id']];
        $noteInfo = $this->state['info'][$type][$note['id']];
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
        // Vydaná faktura: zápočet nese evidovaná platba, ze které čte saldokonto. Kde platba
        // nevznikne (faktura už má platby do uhrazené částky), nevznikne ani zápočet - jinak by
        // zápočet a saldo nesouhlasily. Zápočet má částku platby.
        $paymentId = null;
        if ($type === 'invoice') {
            $paymentId = $this->issuedPayments->record($ctx, $invoice['id'], 'credit_note', null, null, $amount, $note['date'], $noteInfo['number']);
            if ($paymentId === null) {
                return false;
            }
            $paid = $pdo->prepare('SELECT amount FROM invoice_payments WHERE id = ?');
            $paid->execute([$paymentId]);
            $amount = (float) $paid->fetchColumn();
        }
        // Vazbu na opravovanou fakturu dostane jen dobropis; záporná faktura (storno) s rodičem
        // by přestala být dokladem k vyplacení, který vyrovnává jeho stav.
        if ($noteInfo['kind'] === 'credit_note') {
            $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
            $parent = $type === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
            $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
                ->execute([$invoice['id'], $note['id'], $ctx->supplierId]);
        }
        $pdo->prepare(
            'INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $type, $invoice['id'], $note['id'], number_format($amount, 2, '.', ''), $note['date'], $paymentId,
            $ctx->userId > 0 ? $ctx->userId : null]);
        return true;
    }

    /**
     * Vyrovnání interními doklady skupiny jako zápočty proti protiúčtu řádku
     * (`invoice_settlements` s vazbou na zápis interního dokladu), stejně jako zápočet
     * proti účtu v MyÚčtu. Vydaná faktura dostane i evidovanou platbu `settlement`
     * (saldokonto vydaných faktur čte jen ji). Zápis interního dokladu, který vyrovnává
     * víc faktur (vzájemný zápočet přes 395), se rozpadne podle svých řádků.
     *
     * @param array<string,mixed> $g
     * @param array<string,array<string,mixed>> $docs
     */
    private function settleByInternalDocuments(array $g, array $docs, int $sign): void
    {
        if ($g['manual'] === []) {
            return;
        }
        foreach ($g['manual'] as $key => $_) {
            foreach ($this->state['entries'][$key] ?? [] as $entryId) {
                foreach ($g['docs'] as $ref => $d) {
                    $this->entryDocs[$entryId][$d['type']][$ref] = true;
                }
            }
        }
        // Čistý úbytek salda ze všech interních dokladů skupiny (v Kč a v měnách dokladů).
        $leftCzk = 0.0;
        $leftF = [];
        foreach ($g['manual'] as $e) {
            $leftCzk -= $sign * $e['net'];
            if ($e['mena'] !== 'CZK') {
                $leftF[$e['mena']] = ($leftF[$e['mena']] ?? 0.0) - $sign * $e['fnet'];
            }
        }
        $entries = $g['manual'];
        uasort($entries, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        foreach ($entries as $key => $e) {
            if (($e['net'] > 0 ? 1 : -1) === $sign || abs($e['net']) < 0.01) {
                continue; // kurzový zisk, který saldo zvyšuje
            }
            $entryIds = $this->state['entries'][$key] ?? [];
            if (count($entryIds) !== 1) {
                continue;
            }
            $entryId = $entryIds[0];
            $availableCzk = abs($e['net']);
            $availableF = abs($e['fnet']);
            foreach ($docs as $ref => $d) {
                if (isset($this->state['linked'][$ref]['e' . $entryId])) {
                    continue;
                }
                $leftDocF = abs($d['fnet']) - ($this->state['allocatedF'][$ref][$d['mena']] ?? 0.0);
                $docRate = $this->state['info'][$d['type']][$d['id']]['rate'] ?? null;
                if ($d['mena'] === 'CZK') {
                    $amount = round(min($availableCzk, $leftCzk, $this->capacity[$ref]), 2);
                    $czk = $amount;
                } elseif ($e['mena'] === $d['mena'] && $availableF >= 0.01) {
                    $amount = round(min($availableF, $leftF[$d['mena']] ?? 0.0, $leftDocF), 2);
                    $czk = round($amount * abs($e['net']) / abs($e['fnet']), 2);
                } elseif ($e['mena'] === 'CZK' && $docRate !== null) {
                    // Korunový zápočet či kurzový rozdíl dokladu v cizí měně (úhrada z korunového
                    // účtu přepočtená kurzem dokladu na nominál nedosáhne) - kurzem dokladu.
                    $czk = round(min($availableCzk, $leftCzk, $this->capacity[$ref], $leftDocF * $docRate), 2);
                    $amount = round($czk / $docRate, 2);
                } else {
                    continue;
                }
                if ($amount < 0.01) {
                    continue;
                }
                $this->internal[$entryId][$d['type']][$ref] = ['doc' => $d, 'amount' => $amount, 'entry' => $e];
                $this->state['linked'][$ref]['e' . $entryId] = true;
                $this->capacity[$ref] = max(0.0, $this->capacity[$ref] - $czk);
                $availableCzk -= $czk;
                $leftCzk -= $czk;
                if ($d['mena'] !== 'CZK') {
                    $this->state['allocatedF'][$ref][$d['mena']] = ($this->state['allocatedF'][$ref][$d['mena']] ?? 0.0) + $amount;
                    if ($e['mena'] === $d['mena']) {
                        $availableF -= $amount;
                        $leftF[$d['mena']] -= $amount;
                    }
                }
                if ($availableCzk < 0.01) {
                    break;
                }
            }
        }
    }

    /**
     * Vyrovnání interními doklady do evidence. Zápis, který vyrovnává jediný doklad svého
     * druhu, se na doklad naváže jako ruční zápis ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}) -
     * tak ho čte saldokonto i kontroly uzávěrky. Zápis vyrovnávající víc dokladů téhož druhu
     * (vzájemný zápočet přes 395) vazba neunese, nenese rozpad částky; ten se zapíše jako
     * zápočty proti protiúčtu po dokladech ({@see recordSettlement()}). Vazba jen k dokladu
     * s vlastním zaúčtovaným zápisem a jen zápis, který jiný doklad téhož druhu nevyrovnává.
     */
    private function recordInternalSettlements(): void
    {
        $ctx = $this->ctx;
        $link = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($this->internal as $entryId => $byType) {
            foreach ($byType as $type => $docs) {
                $first = reset($docs);
                $linked = $this->state['manualLinks'][$entryId][$type] ?? [];
                if (count($this->entryDocs[$entryId][$type] ?? []) === 1 && $linked === [] && isset($this->state['booked'][$type][$first['doc']['id']])
                    && !isset($this->state['settledEntries'][$entryId][$type])) {
                    $link->execute([$ctx->supplierId, $entryId, $type, $first['doc']['id'], 'Vyrovnání interním dokladem ' . $first['entry']['doc'] . ' (převzato z Money S3)',
                        $ctx->userId > 0 ? $ctx->userId : null]);
                    if ($link->rowCount() > 0) {
                        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'internal_settlement_linked');
                    }
                    continue;
                }
                if ($linked !== []) {
                    continue; // zápis už je vazbou na doklad (dřívější běh nebo uživatel)
                }
                foreach ($docs as $item) {
                    if ($this->recordSettlement($item['doc'], $entryId, $item['amount'], $item['entry'])) {
                        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'internal_settlement');
                    }
                }
            }
        }
    }

    /**
     * Zápočet proti protiúčtu řádku interního dokladu. Částka je v měně dokladu.
     *
     * @param array<string,mixed> $d
     * @param array<string,mixed> $e
     */
    private function recordSettlement(array $d, int $entryId, float $amount, array $e): bool
    {
        $ctx = $this->ctx;
        $accountId = $this->state['accounts'][AccountCode::fromMoney($e['counter']) ?? ''] ?? null;
        if ($accountId === null) {
            return false;
        }
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM invoice_settlements WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND journal_entry_id = ?');
        $exists->execute([$ctx->supplierId, $d['type'], $d['id'], $entryId]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $paymentId = null;
        if ($d['type'] === 'invoice') {
            $paymentId = $this->issuedPayments->record($ctx, $d['id'], 'settlement', null, null, $amount, $e['date'], $e['doc']);
            if ($paymentId === null) {
                return false;
            }
        }
        $pdo->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        )->execute([$ctx->supplierId, $d['type'], $d['id'], $e['date'], number_format($amount, 2, '.', ''), $accountId,
            'Převzato z Money S3, interní doklad ' . $e['doc'], $entryId, $paymentId, $ctx->userId > 0 ? $ctx->userId : null]);
        return true;
    }

    /**
     * Překlep v symbolu: prohozené dva sousední znaky (stejná délka), nebo jedna vypuštěná či
     * přidaná číslice. Záměna jedné číslice překlepem není - `…0123` a `…0124` jsou sousední
     * faktury téže řady.
     */
    public static function typo(string $a, string $b): bool
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === $lb) {
            $diff = [];
            for ($i = 0; $i < $la; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diff[] = $i;
                }
            }
            return count($diff) === 2 && $diff[1] === $diff[0] + 1 && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
        }
        if (abs($la - $lb) !== 1) {
            return false;
        }
        [$long, $short] = $la > $lb ? [$a, $b] : [$b, $a];
        for ($i = 0, $n = strlen($long); $i < $n; $i++) {
            if (ctype_digit($long[$i]) && substr($long, 0, $i) . substr($long, $i + 1) === $short) {
                return true;
            }
        }
        return false;
    }

    /** Pohyb úhrady podle čísla dokladu a roku; stejné číslo na dvou účtech rozliší datum. */
    private function resolve(array $pay): int|string
    {
        $isBank = $pay['zdroj'] === 'BK';
        $ids = ($isBank ? $this->bankByDoc : $this->cashByDoc)[$pay['year'] . '|' . $pay['doc']] ?? [];
        if (count($ids) > 1) {
            $ids = array_values(array_filter($ids, fn (int $id): bool =>
                ($isBank ? ($this->movements[$id]['date'] ?? null) : ($this->state['cash'][$id]['date'] ?? null)) === $pay['date']));
        }
        return count($ids) === 1 ? $ids[0] : ($ids === [] ? 'movement_missing' : 'ambiguous');
    }

    /**
     * Řádky deníku převáděných roků s párovacím symbolem na saldokontních účtech (31x, 32x),
     * seskupené podle symbolu a účtu: předpisy převedených faktur, úhrady banky a pokladny
     * a interní doklady (s protiúčtem řádku).
     *
     * @return array<string,array<string,mixed>>
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
            foreach ([[$effect['debit'], $effect['amount'], $effect['credit']], [$effect['credit'], -$effect['amount'], $effect['debit']]] as [$account, $delta, $counter]) {
                if (preg_match('/^3[12]/', $account) !== 1) {
                    continue;
                }
                $fdelta = $delta > 0 ? $foreign : -$foreign;
                $gk = $sym . '|' . $account;
                $groups[$gk] ??= ['ico' => '', 'docs' => [], 'pay' => [], 'manual' => []];
                if ($doc[0] === 'manual') {
                    $mk = $year . '|' . trim((string) $r['Zdroj']) . '|' . $docNo . '|' . $date;
                    $groups[$gk]['manual'][$mk] ??= ['doc' => $docNo, 'date' => $date, 'net' => 0.0, 'fnet' => 0.0, 'mena' => $mena, 'counter' => $counter];
                    $groups[$gk]['manual'][$mk]['net'] += $delta;
                    $groups[$gk]['manual'][$mk]['fnet'] += $fdelta;
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
        // Skupina jen s předpisem zůstává: může k ní patřit úhrada se symbolem s překlepem.
        return array_filter($groups, static fn (array $g): bool => $g['docs'] !== [] || $g['pay'] !== []);
    }

    /**
     * Stav firmy, ze kterého párování vychází - jedním dotazem na tabulku: evidované úhrady
     * po dokladech (spárované pohyby, pokladna, zápočty i platby zadané v MyÚčtu) a pohybech,
     * pokladní doklady, druh a měna dokladů, zápisy interních dokladů z mapy převodu a účty.
     *
     * Částky jsou v Kč u korunových dokladů; u dokladů v cizí měně jdou úhrady v jejich
     * měně do `allocatedF`.
     *
     * @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements
     * @return array<string,mixed>
     */
    private function state(ImportContext $ctx, array $movements): array
    {
        $pdo = $this->db->pdo();
        $sid = $ctx->supplierId;
        $state = ['allocated' => [], 'allocatedF' => [], 'linked' => [], 'txUsed' => [], 'cash' => [], 'info' => [], 'entries' => [], 'accounts' => [],
            'booked' => [], 'manualLinks' => [], 'settledEntries' => []];

        foreach ([
            'invoice' => 'SELECT d.id, d.invoice_type AS kind, d.exchange_rate, d.varsymbol, cur.code FROM invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
            'purchase_invoice' => 'SELECT d.id, d.document_kind AS kind, d.exchange_rate, d.varsymbol, cur.code FROM purchase_invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
        ] as $type => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sid]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $state['info'][$type][(int) $r['id']] = [
                    'kind' => (string) $r['kind'], 'currency' => (string) $r['code'], 'number' => (string) $r['varsymbol'],
                    'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                ];
            }
        }
        // Částka v měně dokladu → do Kč, resp. do úhrad v cizí měně.
        $add = static function (array &$state, string $ref, string $type, int $id, float $amount): void {
            $currency = $state['info'][$type][$id]['currency'] ?? 'CZK';
            if ($currency === 'CZK') {
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $amount;
            } else {
                $state['allocatedF'][$ref][$currency] = ($state['allocatedF'][$ref][$currency] ?? 0.0) + $amount;
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $amount * ($state['info'][$type][$id]['rate'] ?? 1.0);
            }
        };

        $pm = $pdo->prepare('SELECT bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches WHERE supplier_id = ?');
        $pm->execute([$sid]);
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
        $cash->execute([$sid]);
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

        // Zápočty (převzaté i zadané v MyÚčtu) a ruční platby vydaných faktur.
        $settlements = $pdo->prepare("SELECT doc_type, doc_id, amount, journal_entry_id FROM invoice_settlements WHERE supplier_id = ? AND status = 'confirmed'");
        $settlements->execute([$sid]);
        while ($r = $settlements->fetch(PDO::FETCH_ASSOC)) {
            $ref = $r['doc_type'] . ':' . $r['doc_id'];
            $add($state, $ref, (string) $r['doc_type'], (int) $r['doc_id'], (float) $r['amount']);
            if ($r['journal_entry_id'] !== null) {
                $state['linked'][$ref]['e' . (int) $r['journal_entry_id']] = true;
                $state['settledEntries'][(int) $r['journal_entry_id']][(string) $r['doc_type']] = true;
            }
        }
        // Zaúčtované doklady a vazby ručních zápisů (převzaté i zadané v MyÚčtu).
        $links = $pdo->prepare(
            "SELECT dl.entry_id, dl.doc_type, dl.doc_id, e.source_type
               FROM journal_entry_document_links dl
               JOIN journal_entries e ON e.id = dl.entry_id AND e.supplier_id = dl.supplier_id
              WHERE dl.supplier_id = ? AND dl.doc_type IN ('invoice', 'purchase_invoice')"
        );
        $links->execute([$sid]);
        while ($r = $links->fetch(PDO::FETCH_ASSOC)) {
            if ((string) $r['source_type'] === (string) $r['doc_type']) {
                $state['booked'][(string) $r['doc_type']][(int) $r['doc_id']] = true;
            } elseif ((string) $r['source_type'] === 'manual') {
                $state['manualLinks'][(int) $r['entry_id']][(string) $r['doc_type']][] = (int) $r['doc_id'];
                $state['linked'][$r['doc_type'] . ':' . $r['doc_id']]['e' . (int) $r['entry_id']] = true;
            }
        }
        $offsets = $pdo->prepare(
            "SELECT oi.doc_type, oi.doc_id, oi.amount FROM offset_agreement_items oi
               JOIN offset_agreements oa ON oa.id = oi.agreement_id AND oa.status = 'confirmed'
              WHERE oi.supplier_id = ?
             UNION ALL
             SELECT doc_type, invoice_id, amount FROM credit_note_offsets WHERE supplier_id = ?
             UNION ALL
             SELECT 'invoice', invoice_id, amount FROM invoice_payments
              WHERE supplier_id = ? AND source IN ('manual', 'mark_paid', 'legacy') AND bank_transaction_id IS NULL"
        );
        $offsets->execute([$sid, $sid, $sid]);
        while ($r = $offsets->fetch(PDO::FETCH_ASSOC)) {
            $add($state, $r['doc_type'] . ':' . $r['doc_id'], (string) $r['doc_type'], (int) $r['doc_id'], (float) $r['amount']);
        }

        // Zápisy interních dokladů z mapy převodu (klíč `rok|zdroj|číslo|datum`), jen živé
        // ruční zápisy firmy - zápis mohl uživatel smazat nebo stornovat.
        $liveManual = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND reversed_by IS NULL");
        $liveManual->execute([$sid]);
        $live = array_fill_keys(array_map('intval', $liveManual->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($this->map->all($sid, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) >= 4 && Ms3Journal::sourceType($parts[1]) === 'manual' && isset($live[(int) $entryId])) {
                $state['entries'][$parts[0] . '|' . $parts[1] . '|' . $parts[2] . '|' . $parts[3]][] = (int) $entryId;
            }
        }
        $accounts = $pdo->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $accounts->execute([$sid]);
        while ($r = $accounts->fetch(PDO::FETCH_ASSOC)) {
            $state['accounts'][(string) $r['account_code']] = (int) $r['id'];
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
