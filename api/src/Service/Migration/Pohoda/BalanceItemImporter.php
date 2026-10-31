<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use PDO;

/**
 * Zůstatky 315 a 325 bez dokladu: bankovní a pokladní pohyb, který POHODA zaúčtovala přímo
 * na účet (typicky přeplatek nebo chybná platba na analytice 315.999 a 325.999, někdy později
 * vrácená). V agendě Ostatní pohledávky a závazky doklad nemá, hlavní kniha ho nese
 * a saldokonto by ukazovalo rozdíl.
 *
 * Po účtu a partnerovi se spočítá, co z takových řádků zůstává nevyrovnané. Platba a vrácení
 * téhož partnera a částky se vyruší, jinak vrácení ubírá z nejstarších řádků opačné strany.
 * Zbytek dostane ostatní položku nad svým řádkem ({@see JournalSettlementService::createOtherItem}):
 * nevzniká nový zápis a zůstatek účtu se nemění.
 *
 * Partnera určí protiúčet, u pohybu bez něj název, nakonec variabilní symbol. Řádek bez všech
 * tří zůstává jen v protokolu. Položka nesmí rozdíl saldokonta zvětšit ani překročit, měřeno
 * na syntetice i na analytice: vrácení přeplatku z počátečního stavu (ten pohyb nemá, není
 * s čím párovat) by ho jinak z nuly otevřelo.
 *
 * Opakovaný převod: řádek s položkou z mapy se počítá jen zbytkem částky, takže se nic
 * nezdvojí a nové vrácení dostane vlastní položku opačné strany. Položku, kterou mezitím
 * účetní stornovala, převod znovu nezaloží.
 */
final class BalanceItemImporter
{
    private const STEP = DocumentLinker::STEP_PAYMENTS;
    /** Do kolika dnů se opačný řádek stejné částky bere jako vrácená platba. */
    private const RETURN_DAYS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly JournalSettlementService $settlements,
        private readonly SaldoService $saldo,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $asOf = $ctx->lastPeriodEnd();
        if ($asOf === null || $ctx->period === null) {
            return;
        }
        $accountIds = $this->accountIds($ctx->supplierId);
        $lines = $accountIds === [] ? [] : $this->lines($ctx->supplierId, $accountIds, $asOf);
        if ($lines === []) {
            return;
        }
        $p = $ctx->protocol;
        $mapped = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_BALANCE_ITEM);
        [$covered, $foreign] = $this->allocations($ctx->supplierId, $accountIds, $mapped);
        $explained = $this->explainedMovements($ctx->supplierId);

        $open = [];
        foreach ($lines as $line) {
            $key = $line['entry_id'] . '|' . $line['line_no'];
            $movement = $line['source_type'] . '|' . $line['source_id'] . '|';
            if (isset($foreign[$key]) || isset($explained[$movement . $line['synthetic']]) || isset($explained[$movement . '*'])) {
                continue;
            }
            $open[] = $line + ['key' => $key, 'cents' => self::cents($line['amount']) - ($covered[$key] ?? 0)];
        }

        $candidates = [];
        foreach (self::byPartner($open) as $partnerKey => $group) {
            if ($partnerKey === '') {
                foreach ($group as $line) {
                    $p->count(self::STEP, 'balance_unclear');
                    $p->warn(self::STEP, 'balance_item_unclear', sprintf(
                        'Pohyb %s na účtu %s (%s Kč) nemá partnera ani symbol. Ostatní položku jsme nezaložili, doplňte ji v saldokontu ručně.',
                        $line['document_no'] ?? '#' . $line['entry_id'], $line['account_code'], self::money($line['cents']),
                    ), ['document_no' => $line['document_no']]);
                }
                continue;
            }
            $rest = self::remainders($group);
            $paired = count(array_filter($group, static fn (array $l): bool => $l['cents'] > 0)) - count($rest);
            if ($paired > 0) {
                $p->count(self::STEP, 'balance_paired', $paired);
            }
            array_push($candidates, ...$rest);
        }
        $before = count($candidates);
        $candidates = self::withoutReturns($candidates);
        if ($before > count($candidates)) {
            $p->count(self::STEP, 'balance_paired', (int) (($before - count($candidates)) / 2));
        }
        $candidates = $this->withoutClosedPeriods($ctx, $candidates);
        $accepted = $this->reducingDifference($ctx, $candidates, (int) $ctx->period['id'], $asOf);
        if ($accepted === []) {
            return;
        }

        $snapshots = $this->partnerSnapshots($ctx, $accepted);
        $pdo = $this->db->pdo();
        foreach ($accepted as $line) {
            $snapshot = $snapshots[$line['source_type'] . '|' . $line['source_id']] ?? null;
            $partnerId = $snapshot !== null && ($snapshot['name'] !== '' || $snapshot['ico'] !== '')
                ? $this->partners->resolvePartner($ctx, $snapshot)
                : null;
            $symbol = (string) ($line['variable_symbol'] ?? '');
            $pdo->exec('SAVEPOINT pohoda_balance_item');
            try {
                $created = $this->settlements->createOtherItem($ctx->supplierId, [
                    'line_id' => (int) $line['line_id'],
                    'amount' => $line['cents'] / 100,
                    'partner_id' => $partnerId,
                    'partner_name' => $partnerId === null ? mb_substr((string) ($line['partner_name'] ?? ''), 0, 190) : null,
                    'variable_symbol' => preg_match('/^[0-9]{1,20}$/', $symbol) === 1 ? $symbol : null,
                ], $ctx->userOrNull(), true);
                $pdo->exec('RELEASE SAVEPOINT pohoda_balance_item');
            } catch (JournalSettlementException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_balance_item');
                $pdo->exec('RELEASE SAVEPOINT pohoda_balance_item');
                $p->count(self::STEP, 'balance_not_created');
                $p->warn(self::STEP, 'balance_item_refused', 'Pohyb ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' na účtu ' . $line['account_code'] . ': ' . $e->getMessage(), ['document_no' => $line['document_no']]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_BALANCE_ITEM, self::mapKey($line['key'], $mapped), (int) $created['item']['id'], $ctx->runId);
            $p->count(self::STEP, 'balance_items');
        }
    }

    /**
     * Zbytek nevyrovnaných řádků jednoho účtu a partnera (`cents` = dosud nepokrytá částka).
     * Platba a vrácení stejné částky se vyruší nejstarší s nejstarším, zbytek opačné strany
     * ubírá od nejstaršího řádku. Výsledek je jen na straně, která převažuje, a jeho součet
     * je právě rozdíl obou stran, takže opakovaný výpočet po založení položek vrátí nic.
     *
     * @param list<array<string,mixed>> $lines v pořadí data, s klíči `side` a `cents`
     * @return list<array<string,mixed>>
     */
    public static function remainders(array $lines): array
    {
        $debit = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'debit' && $l['cents'] > 0));
        $credit = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit' && $l['cents'] > 0));
        foreach ($debit as $i => $d) {
            foreach ($credit as $j => $c) {
                if ($c['cents'] === $d['cents']) {
                    unset($debit[$i], $credit[$j]);
                    break;
                }
            }
        }
        $debitTotal = array_sum(array_column($debit, 'cents'));
        $creditTotal = array_sum(array_column($credit, 'cents'));
        $rest = $debitTotal >= $creditTotal ? $debit : $credit;
        $offset = min($debitTotal, $creditTotal);
        $out = [];
        foreach ($rest as $line) {
            $used = min($offset, $line['cents']);
            $offset -= $used;
            if ($line['cents'] - $used > 0) {
                $out[] = ['cents' => $line['cents'] - $used] + $line;
            }
        }
        return $out;
    }

    /**
     * Vrácená platba: banka ji vrací s jiným názvem a často bez protiúčtu („Neexistující účet",
     * „Vracena platba"), po partnerovi se tedy s platbou nesejde. Řádky opačné strany téhož
     * účtu a stejné částky do {@see RETURN_DAYS} dnů se vyruší bez ohledu na partnera,
     * nejbližší s nejbližším.
     *
     * @param list<array<string,mixed>> $lines s klíči `account_code`, `side`, `cents`, `entry_date`
     * @return list<array<string,mixed>>
     */
    public static function withoutReturns(array $lines): array
    {
        $lines = array_values($lines);
        $used = [];
        foreach ($lines as $i => $a) {
            if (isset($used[$i]) || $a['side'] !== 'debit') {
                continue;
            }
            $best = null;
            $bestDays = self::RETURN_DAYS + 1;
            foreach ($lines as $j => $b) {
                if (isset($used[$j]) || $b['side'] !== 'credit' || $b['cents'] !== $a['cents'] || $b['account_code'] !== $a['account_code']) {
                    continue;
                }
                $days = abs((int) ((strtotime((string) $a['entry_date']) - strtotime((string) $b['entry_date'])) / 86400));
                if ($days < $bestDays) {
                    $best = $j;
                    $bestDays = $days;
                }
            }
            if ($best !== null) {
                $used[$i] = true;
                $used[$best] = true;
            }
        }
        return array_values(array_diff_key($lines, $used));
    }

    /**
     * Změna rozdílu saldokonta je přijatelná, když ho zmenší a nepřekročí: položka větší než
     * rozdíl by jen přesunula nesoulad na opačnou stranu.
     */
    public static function narrows(int $difference, int $change): bool
    {
        $after = $difference - $change;
        return abs($after) < abs($difference) && ($after === 0 || ($after > 0) === ($difference > 0));
    }

    /**
     * Řádky rozdělené po účtu a partnerovi; klíč '' = partnera nejde určit. Protiúčet je
     * nejsilnější: pohyb bez něj se k němu přidá, když jeho název nebo symbol ukazuje právě na
     * jeden protiúčet. Stejně se pohyb jen se symbolem přidá k jedinému názvu s tím symbolem.
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string,list<array<string,mixed>>>
     */
    private static function byPartner(array $lines): array
    {
        $owners = [];
        foreach ($lines as $i => $line) {
            $identity = self::identity($line);
            $lines[$i]['identity'] = $identity;
            foreach ([$identity['account'] ?? null, $identity['name'] ?? null] as $owner) {
                if ($owner === null) {
                    continue;
                }
                foreach (array_filter([$identity['name'] ?? null, $identity['symbol'] ?? null]) as $alias) {
                    if ($alias !== $owner) {
                        $owners[$line['account_code']][$alias][$owner] = true;
                    }
                }
            }
        }
        $groups = [];
        foreach ($lines as $line) {
            $identity = $line['identity'];
            $key = $identity['account'] ?? null;
            foreach (array_filter([$identity['name'] ?? null, $identity['symbol'] ?? null]) as $alias) {
                if ($key !== null) {
                    break;
                }
                $found = array_keys($owners[$line['account_code']][$alias] ?? []);
                $accounts = array_values(array_filter($found, static fn (string $o): bool => str_starts_with($o, 'a:')));
                $key = count($accounts) === 1 ? $accounts[0] : null;
            }
            if ($key === null && isset($identity['symbol']) && !isset($identity['name'])) {
                $found = array_keys($owners[$line['account_code']][$identity['symbol']] ?? []);
                $key = count($found) === 1 ? $found[0] : null;
            }
            $key ??= $identity['name'] ?? $identity['symbol'] ?? null;
            $groups[$key === null ? '' : $line['account_code'] . '|' . $key][] = $line;
        }
        return $groups;
    }

    /**
     * @param array<string,mixed> $line
     * @return array{account?:string,name?:string,symbol?:string}
     */
    private static function identity(array $line): array
    {
        $out = [];
        $account = trim((string) ($line['counterparty_account'] ?? ''));
        if (ltrim($account, '0-') !== '') {
            $out['account'] = 'a:' . $account . '/' . trim((string) ($line['counterparty_bank'] ?? ''));
        }
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($line['partner_name'] ?? '')) ?? ''));
        if ($name !== '') {
            $out['name'] = 'n:' . $name;
        }
        $symbol = trim((string) ($line['variable_symbol'] ?? ''));
        if (ltrim($symbol, '0') !== '') {
            $out['symbol'] = 'v:' . $symbol;
        }
        return $out;
    }

    /**
     * Uzavřené období se nemění: položka by dodatečně změnila saldokonto k jeho konci.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function withoutClosedPeriods(PohodaContext $ctx, array $candidates): array
    {
        $out = [];
        foreach ($candidates as $line) {
            if ($line['period_status'] !== null && AccountingPeriodStatus::isClosed((string) $line['period_status'])) {
                $ctx->protocol->count(self::STEP, 'balance_not_created');
                $ctx->protocol->warn(self::STEP, 'balance_item_closed_period', 'Pohyb ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' na účtu ' . $line['account_code'] . ' je v uzavřeném období, ostatní položku jsme nezaložili.', ['document_no' => $line['document_no']]);
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Jen položky, které rozdíl saldokonta proti hlavní knize nezvětší. Sedí-li všechny
     * dohromady, založí se všechny (dva opačné přeplatky se v rozdílu vyruší). Jinak se berou
     * od největší a každá ho musí zmenšit, ostatní zůstanou jen v protokolu.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function reducingDifference(PohodaContext $ctx, array $candidates, int $periodId, string $asOf): array
    {
        $bySynthetic = [];
        foreach ($candidates as $line) {
            $bySynthetic[$line['synthetic']][] = $line;
        }
        $accepted = [];
        foreach ($bySynthetic as $synthetic => $rows) {
            try {
                $block = $this->saldo->build($ctx->supplierId, $periodId, $asOf, (string) $synthetic)['accounts'][0] ?? null;
            } catch (ReportException $e) {
                $ctx->protocol->warn(self::STEP, 'balance_item_saldo', "Saldokonto účtu {$synthetic} nejde sestavit, ostatní položky ze zůstatků jsme nezaložili: " . $e->getMessage());
                continue;
            }
            if ($block === null) {
                continue;
            }
            $debitNormal = $block['account']['normal_side'] === 'debit';
            $signed = static fn (array $l): int => ($l['side'] === 'debit') === $debitNormal ? $l['cents'] : -$l['cents'];
            // Rozdíl se měří i na analytice: nevysvětlený zůstatek 315.900 nedává důvod
            // k položce na 315.999, jejíž zůstatek je nula.
            $syntheticDifference = self::cents($block['difference']);
            $byAnalytic = [];
            foreach ($rows as $line) {
                $byAnalytic[$line['account_code']][] = $line;
            }
            foreach ($byAnalytic as $account => $group) {
                $difference = $this->analyticDifference($ctx->supplierId, (string) $account, $debitNormal, $asOf);
                $total = array_sum(array_map($signed, $group));
                if (self::narrows($difference, $total) && self::narrows($syntheticDifference, $total)) {
                    array_push($accepted, ...$group);
                    $syntheticDifference -= $total;
                    continue;
                }
                usort($group, static fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);
                foreach ($group as $line) {
                    if (self::narrows($difference, $signed($line)) && self::narrows($syntheticDifference, $signed($line))) {
                        $difference -= $signed($line);
                        $syntheticDifference -= $signed($line);
                        $accepted[] = $line;
                        continue;
                    }
                    $ctx->protocol->count(self::STEP, 'balance_not_created');
                    $ctx->protocol->warn(self::STEP, 'balance_item_no_effect', sprintf(
                        'Pohyb %s na účtu %s (%s Kč): položka by rozdíl saldokonta zvětšila nebo překročila, protiváha je nejspíš v počátečním stavu nebo jiném zápisu. Zkontrolujte ho ručně.',
                        $line['document_no'] ?? '#' . $line['entry_id'], $line['account_code'], self::money($line['cents']),
                    ), ['document_no' => $line['document_no']]);
                }
            }
        }
        return $accepted;
    }

    /** Zůstatek analytiky k datu na normální straně účtu mínus otevřené ostatní položky na ní, v haléřích. */
    private function analyticDifference(int $supplierId, string $account, bool $debitNormal, string $asOf): int
    {
        $pdo = $this->db->pdo();
        $balance = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)"
        );
        $balance->execute([$supplierId, $account, $asOf]);
        $items = $pdo->prepare(
            "SELECT COALESCE(SUM((CASE WHEN oi.side = 'receivable' THEN 1 ELSE -1 END)
                        * (oi.amount - oi.settled_outside_amount
                           - COALESCE((SELECT SUM(x.amount) FROM other_item_allocations x WHERE x.other_item_id = oi.id AND x.reversed_on IS NULL), 0))), 0)
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.account_code = ? AND oi.deleted_at IS NULL AND oi.status <> 'draft'"
        );
        $items->execute([$supplierId, $account]);
        $difference = self::cents((float) $balance->fetchColumn() - (float) $items->fetchColumn());
        return $debitNormal ? $difference : -$difference;
    }

    /**
     * Partner pohybu z exportu tak, jak ho vede POHODA. Název v pohybu to být nemusí, u pohybu
     * bez partnera ho nahrazuje poznámka, a z té se v adresáři partner nezakládá.
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string,array<string,string>> `bank|id` nebo `cash|id` => snímek partnera
     */
    private function partnerSnapshots(PohodaContext $ctx, array $lines): array
    {
        $wanted = [];
        foreach ($lines as $line) {
            if ($line['source_id'] !== null) {
                $wanted[$line['source_type'] . '|' . $line['source_id']] = true;
            }
        }
        $out = [];
        foreach ($ctx->export->records('bank', 'bank') as $record) {
            $header = PohodaXml::get($record, 'bankHeader');
            $key = 'bank|' . ($ctx->bankByPohodaId[PohodaXml::text($header, 'id')] ?? '');
            if (isset($wanted[$key])) {
                $out[$key] = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
            }
        }
        foreach ($ctx->export->records('cash', 'voucher') as $record) {
            $header = PohodaXml::get($record, 'voucherHeader');
            $key = 'cash|' . ($ctx->cashDocuments[PohodaXml::text($header, 'number/numberRequested')] ?? '');
            if (isset($wanted[$key])) {
                $out[$key] = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
            }
        }
        return $out;
    }

    /** @return list<int> účty 315 a 325 včetně analytik */
    private function accountIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts
            WHERE supplier_id = ? AND (account_code LIKE '315%' OR account_code LIKE '325%')");
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Řádky živých zápisů banky a pokladny na 315 a 325 bez vlastní položky a bez vazby na
     * doklad, od nejstaršího.
     *
     * @param list<int> $accountIds
     * @return list<array<string,mixed>>
     */
    private function lines(int $supplierId, array $accountIds, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id AS line_id, l.entry_id, l.line_no, l.side, l.amount, a.account_code, LEFT(a.account_code, 3) AS synthetic,
                    e.document_no, e.entry_date, e.source_type, e.source_id, ap.status AS period_status,
                    t.counterparty_account, t.counterparty_bank,
                    t.variable_symbol,
                    COALESCE(t.counterparty_name, c.partner_name) AS partner_name
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               LEFT JOIN accounting_periods ap ON ap.id = e.period_id AND ap.supplier_id = e.supplier_id
               LEFT JOIN (bank_transactions t JOIN bank_statements s ON s.id = t.statement_id)
                      ON e.source_type = 'bank' AND t.id = e.source_id AND s.supplier_id = e.supplier_id
               LEFT JOIN cash_documents c ON e.source_type = 'cash' AND c.id = e.source_id AND c.supplier_id = e.supplier_id
              WHERE l.supplier_id = ? AND l.account_id IN (" . implode(',', $accountIds) . ")
                AND l.is_red_storno = 0
                AND e.source_type IN ('bank', 'cash')
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = e.supplier_id AND oi.journal_entry_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links k
                                 WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type IN ('invoice', 'purchase_invoice'))
              ORDER BY e.entry_date, l.id"
        );
        $stmt->execute([$supplierId, $asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Co už na řádcích je: `$covered` = haléře položek z mapy převodu po řádcích (včetně
     * stornovaných, rozhodnutí účetní se nepřepisuje), `$foreign` = řádky s jiným rozpadem.
     *
     * @param list<int> $accountIds
     * @param array<string,int|string> $mapped
     * @return array{0:array<string,int>,1:array<string,true>}
     */
    private function allocations(int $supplierId, array $accountIds, array $mapped): array
    {
        $own = array_fill_keys(array_map('intval', array_values($mapped)), true);
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_id, line_no, kind, doc_type, doc_id, amount FROM journal_settlement_allocations
              WHERE supplier_id = ? AND account_id IN (' . implode(',', $accountIds) . ')'
        );
        $stmt->execute([$supplierId]);
        $covered = [];
        $foreign = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['entry_id'] . '|' . $row['line_no'];
            if ($row['kind'] === 'origin' && $row['doc_type'] === 'other_item' && isset($own[(int) $row['doc_id']])) {
                $covered[$key] = ($covered[$key] ?? 0) + self::cents($row['amount']);
            } else {
                $foreign[$key] = true;
            }
        }
        return [$covered, $foreign];
    }

    /**
     * Pohyby, které hradí doklad vedený na 315 nebo 325 (ostatní položku, fakturu ostatní
     * pohledávky či závazku), takže jejich řádek zůstatek bez dokladu není. Úhrada faktury na
     * 311 nebo 321 řádek na 315 nevysvětluje: tak vypadá přeplatek zaúčtovaný vedle úhrady.
     * Položka bez účtu vysvětluje pohyb na obou účtech (`*`).
     *
     * @return array<string,true> `bank|id|315`
     */
    private function explainedMovements(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $items = $pdo->prepare(
            "SELECT a.bank_transaction_id, a.cash_document_id, COALESCE(LEFT(oi.account_code, 3), '*') AS synthetic
               FROM other_item_allocations a
               JOIN other_items oi ON oi.id = a.other_item_id AND oi.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.reversed_on IS NULL"
        );
        $items->execute([$supplierId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $movement = $row['bank_transaction_id'] !== null ? 'bank|' . $row['bank_transaction_id'] : 'cash|' . $row['cash_document_id'];
            $out[$movement . '|' . $row['synthetic']] = true;
        }
        $documents = $pdo->prepare(
            "WITH paid AS (
                 SELECT 'bank' AS kind, bank_transaction_id AS movement, 'invoice' AS doc_type, invoice_id AS doc_id
                   FROM payment_matches WHERE supplier_id = ? AND invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'bank', bank_transaction_id, 'purchase_invoice', purchase_invoice_id
                   FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'cash', id, 'invoice', invoice_id
                   FROM cash_documents WHERE supplier_id = ? AND invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'cash', id, 'purchase_invoice', purchase_invoice_id
                   FROM cash_documents WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
             )
             SELECT DISTINCT p.kind, p.movement, LEFT(a.account_code, 3) AS synthetic
               FROM paid p
               JOIN journal_entry_document_links k ON k.supplier_id = ? AND k.doc_type = p.doc_type AND k.doc_id = p.doc_id
               JOIN journal_entry_lines l ON l.supplier_id = k.supplier_id AND l.entry_id = k.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE a.account_code LIKE '315%' OR a.account_code LIKE '325%'"
        );
        $documents->execute(array_fill(0, 5, $supplierId));
        foreach ($documents->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['kind'] . '|' . $row['movement'] . '|' . $row['synthetic']] = true;
        }
        return $out;
    }

    /**
     * Klíč mapy řádku: další položka téhož řádku (zbytek po mezitím změněném párování) dostane pořadí.
     *
     * @param array<string,int|string> $mapped
     */
    private static function mapKey(string $key, array &$mapped): string
    {
        $candidate = $key;
        for ($i = 2; isset($mapped[$candidate]); $i++) {
            $candidate = $key . '#' . $i;
        }
        $mapped[$candidate] = 0;
        return $candidate;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }
}
