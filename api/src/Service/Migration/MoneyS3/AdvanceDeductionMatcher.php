<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Čerpání poskytnutých záloh (314), které Money vede bez vazby na zálohu.
 *
 * Money vyúčtuje zálohu řádkem konečné faktury na zálohovém účtu („vyúčtované zálohy",
 * „odpočet - vyúčtování plynu", „uhrazené zálohy 12x"), který nese symbol a IČO faktury,
 * ne zálohy. Položka s odkazem na zálohu (`CisloZaFak`) chybí, takže {@see AdvanceLinker}
 * vazbu nepozná a saldokonto drží zálohu i daňový doklad k přijaté platbě (DDKP) otevřené,
 * přestože je hlavní kniha čerpání nese. Saldo Money stojí na partnerovi a účtu: počáteční
 * stavy vede bez párovacího symbolu.
 *
 * Proto se čerpání páruje po partnerovi (IČO) napříč analytikami 314 - Money přeúčtovává
 * zálohu i daň mezi analytikami (platba 314.610, základ DDKP 314.612, vyúčtování jinde):
 *  - zálohou je otevřená položka saldokonta 314 převzatého dokladu (záloha, samostatný DDKP,
 *    záloha placená v konečné faktuře), s otevřenou částkou podle saldokonta - u DDKP tedy
 *    základ, daň čerpá DDKP sám,
 *  - čerpáním je kredit převzaté konečné faktury na 314 (bez přeúčtování mezi analytikami
 *    v témž zápisu, stejně jako ve {@see SaldoRepository}), snížený o už evidované čerpání,
 *  - nejstarší je počáteční stav partnera na 314 v prvním převáděném roce (zálohy z doby
 *    před převodem); čerpání ho spotřebuje nejdřív a vazbu k němu nezakládá,
 *  - čerpání, jehož řádek v deníku Money uvádí číslo zálohy, DDKP nebo bankovního dokladu
 *    úhrady („Odpočet zálohy ZF190014 (daň. dokl. DPP190023)"), jde nejdřív na ni,
 *  - zbytek FIFO: čerpání spotřebuje nejstarší otevřené zálohy partnera zaplacené do data
 *    čerpání, nejvýš do jejich otevřené částky.
 *
 * Výsledek je `purchase_advance_deductions`. Deník se nemění. Vazby zadané v MyÚčtu
 * (`advance_purchase_invoice_id`, ruční čerpání) se nepřepisují a snižují, co zbývá; čerpání,
 * které převod založil a uživatel smazal (mapa převodu), se znovu nezaloží a jeho faktura
 * ani záloha se dál nepárují. Opakovaný převod nic nezdvojí.
 */
final class AdvanceDeductionMatcher
{
    private const ACCOUNT = '314';
    private const EPSILON = 0.005;
    /** Haléřové zbytky (zaokrouhlení DDKP a vyúčtování v Money) FIFO s cizí zálohou nepáruje. */
    private const MIN_FIFO = 1.0;
    private const FAR_FUTURE = '9999-12-31';

    public function __construct(
        private readonly Connection $db,
        private readonly ?MoneyS3ImportRepository $map = null,
    ) {}

    /** @param list<string> $dirs adresáře let, jejichž doklady převod zná */
    public function match(ImportContext $ctx, array $dirs): void
    {
        $years = [];
        foreach ($dirs as $dir) {
            if (isset($ctx->dirYears[$dir])) {
                $years[$ctx->dirYears[$dir]] = $dir;
            }
        }
        if ($years === []) {
            return;
        }
        ksort($years);
        $firstYear = (int) array_key_first($years);
        $from = (string) ($ctx->periods[$firstYear]['starts_on'] ?? ($firstYear . '-01-01'));
        $accountId = $this->accountId($ctx->supplierId);
        if ($accountId === null) {
            return;
        }
        $mapped = $this->map?->all($ctx->supplierId, MoneyS3ImportRepository::KIND_ADVANCE_DEDUCTION) ?? [];
        [$blockedFinals, $blockedAdvances] = $this->userRemoved($ctx->supplierId, $mapped);
        $advances = array_values(array_filter(
            $this->advances($ctx->supplierId, $accountId),
            static fn (array $a): bool => !isset($blockedAdvances[$a['id']]),
        ));
        $deductions = array_values(array_filter(
            $this->deductions($ctx->supplierId, $accountId, $from),
            static fn (array $d): bool => !isset($blockedFinals[$d['id']]),
        ));
        if ($advances === [] || $deductions === []) {
            return;
        }
        [$opening, $refs] = $this->moneyJournal($ctx, $years[$firstYear], array_values($years));
        // Doklad z doby před prvním převáděným rokem (počáteční stav s dokladem) je
        // v počátečním stavu Money zahrnutý; jako záloha se páruje sám.
        foreach ($advances as $a) {
            if ($a['date'] < $from && isset($opening[$a['partner']])) {
                $opening[$a['partner']] = round($opening[$a['partner']] - $a['open'], 2);
            }
        }
        $refIds = $this->referencedAdvances($ctx, $refs, $advances);
        foreach ($deductions as $i => $d) {
            $deductions[$i]['refs'] = $refIds[$d['id']] ?? [];
        }
        $result = self::allocate($opening, $advances, $deductions);
        $this->write($ctx, $result['deductions'], $mapped);
        // Souhrn do počtů kroku: zprávy kroku úhrad se u velké agendy ořezávají.
        $p = $ctx->protocol;
        $p->setCount(DocumentLinker::STEP_PAYMENTS, 'advance_deductions_opening_amount', round($result['opening_consumed'], 2));
        if ($result['unmatched'] !== []) {
            $p->setCount(DocumentLinker::STEP_PAYMENTS, 'advance_deductions_unmatched', count($result['unmatched']));
            $p->setCount(DocumentLinker::STEP_PAYMENTS, 'advance_deductions_unmatched_amount', round(array_sum($result['unmatched']), 2));
            $p->set('advance_deductions_unmatched', array_slice(array_map(
                static fn (int $id, float $amount): array => ['purchase_invoice_id' => $id, 'amount' => $amount],
                array_keys($result['unmatched']), $result['unmatched'],
            ), 0, ImportProtocol::LIST_LIMIT));
        }
    }

    /**
     * Párování čerpání se zálohami po partnerech. Čistá funkce, pořadí výsledku je stabilní.
     *
     * @param array<string,float> $opening partner => počáteční stav zálohového účtu (zálohy před převodem)
     * @param list<array{id:int,partner:string,date:string,open:float}> $advances otevřené zálohy
     * @param list<array{id:int,partner:string,date:string,amount:float,refs?:list<int>}> $deductions nespárovaná čerpání
     * @return array{deductions:array<int,array<int,float>>,unmatched:array<int,float>,opening_consumed:float,opening_left:array<string,float>}
     */
    public static function allocate(array $opening, array $advances, array $deductions): array
    {
        usort($advances, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        usort($deductions, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $left = [];
        $partnerOf = [];
        $byPartner = [];
        foreach ($advances as $a) {
            if ($a['open'] < self::EPSILON) {
                continue;
            }
            $left[$a['id']] = round($a['open'], 2);
            $partnerOf[$a['id']] = $a['partner'];
            $byPartner[$a['partner']][] = $a;
        }
        $openingLeft = array_map(static fn (float $v): float => max(0.0, round($v, 2)), $opening);
        $out = [];
        $take = static function (int $finalId, int $advanceId, float $amount) use (&$out, &$left): float {
            $amount = round(min($amount, $left[$advanceId]), 2);
            if ($amount < 0.01 || $finalId === $advanceId) {
                return 0.0;
            }
            $left[$advanceId] = round($left[$advanceId] - $amount, 2);
            $out[$finalId][$advanceId] = round(($out[$finalId][$advanceId] ?? 0.0) + $amount, 2);
            return $amount;
        };
        $openingConsumed = 0.0;
        $unmatched = [];
        // Čerpání jdou v pořadí, v jakém je Money zaúčtovalo: odkaz na zálohu v textu
        // deníku Money má přednost, pak počáteční stav, pak nejstarší zálohy.
        foreach ($deductions as $d) {
            $need = round($d['amount'], 2);
            foreach ($d['refs'] ?? [] as $advanceId) {
                if ($need < 0.01) {
                    break;
                }
                if (($partnerOf[$advanceId] ?? null) === $d['partner'] && $left[$advanceId] >= 0.01) {
                    $need = round($need - $take($d['id'], $advanceId, $need), 2);
                }
            }
            if ($need < 0.01) {
                continue;
            }
            $fromOpening = round(min($need, $openingLeft[$d['partner']] ?? 0.0), 2);
            if ($fromOpening > 0.0) {
                $openingLeft[$d['partner']] = round($openingLeft[$d['partner']] - $fromOpening, 2);
                $openingConsumed += $fromOpening;
                $need = round($need - $fromOpening, 2);
            }
            foreach ($byPartner[$d['partner']] ?? [] as $a) {
                if ($need < 0.01) {
                    break;
                }
                if ($a['date'] > $d['date'] || min($need, $left[$a['id']]) < self::MIN_FIFO) {
                    continue;
                }
                $need = round($need - $take($d['id'], $a['id'], $need), 2);
            }
            if ($need >= 0.01) {
                $unmatched[$d['id']] = $need;
            }
        }
        ksort($out);
        return ['deductions' => $out, 'unmatched' => $unmatched, 'opening_consumed' => round($openingConsumed, 2), 'opening_left' => $openingLeft];
    }

    private function accountId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? ORDER BY is_synthetic DESC, id LIMIT 1');
        $stmt->execute([$supplierId, self::ACCOUNT]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Čerpání, která převod založil a uživatel v MyÚčtu smazal: jeho faktura ani záloha
     * se dál automaticky nepárují.
     *
     * @param array<string,int> $mapped
     * @return array{0:array<int,true>,1:array<int,true>}
     */
    private function userRemoved(int $supplierId, array $mapped): array
    {
        if ($mapped === []) {
            return [[], []];
        }
        $stmt = $this->db->pdo()->prepare('SELECT final_invoice_id, advance_invoice_id FROM purchase_advance_deductions WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $live = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$final, $advance]) {
            $live[self::mapKey((int) $final, (int) $advance)] = true;
        }
        $finals = [];
        $advances = [];
        foreach ($mapped as $key => $_) {
            if (isset($live[$key])) {
                continue;
            }
            $parts = explode('|', (string) $key);
            $finals[(int) ($parts[1] ?? 0)] = true;
            $advances[(int) ($parts[2] ?? 0)] = true;
        }
        return [$finals, $advances];
    }

    public static function mapKey(int $finalId, int $advanceId): string
    {
        return 'pad|' . $finalId . '|' . $advanceId;
    }

    /**
     * Otevřené položky saldokonta 314 převzatých přijatých dokladů. Otevřená částka je
     * přesně ta, kterou saldokonto ukazuje (SSOT {@see SaldoRepository::openItems()}).
     *
     * @return list<array{id:int,partner:string,date:string,open:float}>
     */
    private function advances(int $supplierId, int $accountId): array
    {
        $open = [];
        foreach ((new SaldoRepository($this->db))->openItems($supplierId, $accountId, self::FAR_FUTURE, self::ACCOUNT) as $item) {
            if (($item['doc_type'] ?? '') !== 'purchase_invoice') {
                continue;
            }
            $remaining = SaldoRepository::settlementAmounts((float) $item['booked_signed'], (float) $item['paid_ratio'])['remaining'];
            if ($remaining >= 0.01) {
                $open[(int) $item['doc_id']] = $remaining;
            }
        }
        if ($open === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_keys($open), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT p.id, p.vendor_id, c.ic, p.issue_date,
                        (SELECT MIN(DATE(bt.posted_at)) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                          WHERE pm.supplier_id = p.supplier_id AND pm.purchase_invoice_id = p.id AND bt.amount < 0) AS paid_on,
                        (SELECT MIN(e.entry_date) FROM journal_entries e
                          WHERE e.supplier_id = p.supplier_id AND e.source_type = "purchase_invoice" AND e.source_id = p.id) AS booked_on
                   FROM purchase_invoices p
                   JOIN clients c ON c.id = p.vendor_id
                  WHERE p.supplier_id = ? AND p.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND ' . TakenOverRecord::documentSql('purchase_invoice', 'p')
            );
            $stmt->execute([$supplierId, ...$chunk]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Záloha je k dispozici od nejdřívějšího z vystavení, úhrady a zaúčtování
                // (DDKP Money účtuje ke dni platby, zálohu placenou fakturou ke dni zápisu).
                $date = min(array_filter(
                    [(string) $r['issue_date'], (string) ($r['paid_on'] ?? ''), (string) ($r['booked_on'] ?? '')],
                    static fn (string $d): bool => $d !== '',
                ));
                $out[] = ['id' => (int) $r['id'], 'partner' => self::partner($r['ic'], (int) $r['vendor_id']), 'date' => $date, 'open' => $open[(int) $r['id']]];
            }
        }
        return $out;
    }

    /**
     * Kredity převzatých konečných faktur na 314 od prvního převáděného roku, snížené
     * o evidované čerpání faktury. Faktura s vazbou na zálohu se nebere: saldokonto jí
     * přičítá celý kredit.
     *
     * @return list<array{id:int,partner:string,date:string,amount:float}>
     */
    private function deductions(int $supplierId, int $accountId, string $from): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.id, d.vendor_id, c.ic, e.id AS entry_id, e.entry_date, l.account_id, l.side, l.signed_amount,
                    (SELECT COALESCE(SUM(pad.amount), 0) FROM purchase_advance_deductions pad
                      WHERE pad.supplier_id = d.supplier_id AND pad.final_invoice_id = d.id) AS deducted
               FROM (" . DocumentPostingGroup::entriesSql('purchase_invoice', $supplierId) . ") de
               JOIN purchase_invoices d ON d.id = de.doc_id AND d.supplier_id = {$supplierId}
               JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.id = de.entry_id
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               JOIN clients c ON c.id = d.vendor_id
              WHERE d.document_kind IN ('invoice', 'credit_note') AND d.status NOT IN ('draft', 'cancelled')
                AND d.advance_purchase_invoice_id IS NULL
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date >= ?
                AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
                AND " . TakenOverRecord::documentSql('purchase_invoice', 'd') . '
              ORDER BY d.id, e.id, l.id'
        );
        $stmt->execute([$from]);
        $docs = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $r['id'];
            $docs[$id]['row'] ??= $r;
            $docs[$id]['entries'][(int) $r['entry_id']][] = $r;
        }
        $out = [];
        foreach ($docs as $id => $doc) {
            $credit = 0.0;
            $date = null;
            foreach ($doc['entries'] as $lines) {
                // Kredit, ke kterému má tentýž zápis stejně velký debet na jiné analytice,
                // je přeúčtování uvnitř zálohového účtu, ne čerpání (zrcadlo položky zálohy
                // placené v konečné faktuře v saldokontu).
                $debits = [];
                foreach ($lines as $l) {
                    if ($l['side'] === 'debit') {
                        $debits[] = [(int) $l['account_id'], round((float) $l['signed_amount'], 2)];
                    }
                }
                foreach ($lines as $l) {
                    if ($l['side'] !== 'credit') {
                        continue;
                    }
                    $amount = round((float) $l['signed_amount'], 2);
                    foreach ($debits as $i => [$debitAccount, $debitAmount]) {
                        if ($debitAccount !== (int) $l['account_id'] && abs($debitAmount - $amount) < self::EPSILON) {
                            unset($debits[$i]);
                            continue 2;
                        }
                    }
                    $credit += $amount;
                    $date = $date === null || (string) $l['entry_date'] < $date ? (string) $l['entry_date'] : $date;
                }
            }
            $amount = round($credit - (float) $doc['row']['deducted'], 2);
            if ($amount >= 0.01 && $date !== null) {
                $out[] = ['id' => $id, 'partner' => self::partner($doc['row']['ic'], (int) $doc['row']['vendor_id']), 'date' => $date, 'amount' => $amount];
            }
        }
        return $out;
    }

    /**
     * Deník Money: počáteční stav zálohového účtu po partnerech v prvním převáděném roce
     * a čísla dokladů, která uvádějí řádky konečných faktur na zálohovém účtu.
     *
     * @param list<string> $dirs
     * @return array{0:array<string,float>,1:array<int,list<array{year:int,no:string}>>}
     */
    private function moneyJournal(ImportContext $ctx, string $firstDir, array $dirs): array
    {
        $opening = [];
        $refs = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'Doklad', 'UcMD', 'UcD', 'Castka', 'ParICO', 'Popis'], $dirs) as $r) {
            $md = str_starts_with(trim((string) ($r['UcMD'] ?? '')), self::ACCOUNT);
            $dal = str_starts_with(trim((string) ($r['UcD'] ?? '')), self::ACCOUNT);
            if (!$md && !$dal) {
                continue;
            }
            if (Ms3Journal::isOpening($r)) {
                if ($r['__dir'] === $firstDir && $md !== $dal) {
                    $partner = self::partner($r['ParICO'] ?? null, 0);
                    $opening[$partner] = round(($opening[$partner] ?? 0.0) + ($md ? 1 : -1) * (float) ($r['Castka'] ?? 0), 2);
                }
                continue;
            }
            $year = $ctx->yearOf($r);
            $docId = $year !== null ? ($ctx->purchaseInvoices[$year . '|' . trim((string) ($r['Doklad'] ?? ''))] ?? null) : null;
            $popis = (string) ($r['Popis'] ?? '');
            if ($docId === null || preg_match_all('/\b[A-Z]{1,5}\d{5,}[A-Z]?\b/u', $popis, $m) < 1) {
                continue;
            }
            // Daňový doklad („daň. dokl. DPP230003") rozhoduje: Money v témže textu někdy uvádí
            // číslo jiné zálohy, než ke které odečítaný DDKP patří.
            $numbers = preg_match('/da[nň]\.?\s*dokl\.?\s*([A-Z]{1,5}\d{5,}[A-Z]?)/u', $popis, $t) === 1 ? [$t[1]] : [];
            foreach (array_unique(array_merge($numbers, $m[0])) as $no) {
                $refs[(int) $docId][] = ['year' => $year, 'no' => $no];
            }
        }
        unset($opening['']);
        return [$opening, $refs];
    }

    /**
     * Čísla dokladů z textu deníku => otevřené zálohy: záloha nebo DDKP téhož či minulého
     * roku, případně záloha uhrazená bankovním dokladem s tím číslem.
     *
     * @param array<int,list<array{year:int,no:string}>> $refs
     * @param list<array{id:int,partner:string,date:string,open:float}> $advances
     * @return array<int,list<int>>
     */
    private function referencedAdvances(ImportContext $ctx, array $refs, array $advances): array
    {
        if ($refs === []) {
            return [];
        }
        $pool = array_fill_keys(array_column($advances, 'id'), true);
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            $parts = explode('|', (string) $key, 3);
            $bankByDoc[$parts[0] . '|' . ($parts[1] ?? '')][] = (int) $id;
        }
        $paidBy = [];
        foreach (array_chunk(array_keys($pool), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare('SELECT bank_transaction_id, purchase_invoice_id FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$tx, $doc]) {
                $paidBy[(int) $tx][(int) $doc] = true;
            }
        }
        $out = [];
        foreach ($refs as $finalId => $list) {
            foreach ($list as ['year' => $year, 'no' => $no]) {
                foreach ([$year, $year - 1] as $y) {
                    $ids = [];
                    if (isset($ctx->purchaseInvoices[$y . '|' . $no])) {
                        $ids[] = $ctx->purchaseInvoices[$y . '|' . $no];
                    }
                    foreach ($bankByDoc[$y . '|' . $no] ?? [] as $tx) {
                        foreach ($paidBy[$tx] ?? [] as $doc => $_) {
                            $ids[] = $doc;
                        }
                    }
                    foreach ($ids as $id) {
                        if (isset($pool[(int) $id]) && (int) $id !== $finalId && !in_array((int) $id, $out[$finalId] ?? [], true)) {
                            $out[$finalId][] = (int) $id;
                        }
                    }
                    if ($ids !== []) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<int,array<int,float>> $deductions
     * @param array<string,int> $mapped
     */
    private function write(ImportContext $ctx, array $deductions, array $mapped): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        foreach ($deductions as $finalId => $advances) {
            foreach ($advances as $advanceId => $amount) {
                $key = self::mapKey($finalId, $advanceId);
                if (isset($mapped[$key]) || $amount < 0.01) {
                    continue;
                }
                $insert->execute([$ctx->supplierId, $finalId, $advanceId, number_format($amount, 2, '.', ''), $userId]);
                if ($insert->rowCount() === 0) {
                    // Dvojici už spojuje jiné čerpání (zadané v MyÚčtu); nepřepisuje se.
                    $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_deduction_pair_exists');
                    continue;
                }
                $this->map?->put($ctx->supplierId, MoneyS3ImportRepository::KIND_ADVANCE_DEDUCTION, $key, (int) $pdo->lastInsertId(), $ctx->runId);
                $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_deductions_matched');
            }
        }
    }

    private static function partner(mixed $ico, int $vendorId): string
    {
        $ico = strtoupper(preg_replace('/\s+/', '', (string) $ico) ?? '');
        return $ico !== '' ? 'ico:' . $ico : ($vendorId > 0 ? 'vendor:' . $vendorId : '');
    }
}
