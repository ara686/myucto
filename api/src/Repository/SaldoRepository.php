<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\CreditNoteRefundExpr;
use MyInvoice\Support\Sql\LinkedManualSettlementSql;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Repository saldokonta (audit 2026-07, nález H13 — fáze D6/1). Otevřené položky
 * účtů pohledávek/závazků (311/321/314/324…) dovozené z faktur přes vazbu
 * hlavičky zápisu (journal_entries.source_type/source_id → invoices/purchase_invoices).
 *
 * FÁZE 1: partner je dovoditelný jen u AUTOMATICKY účtovaných dokladů
 * (source_type invoice/purchase_invoice); ruční a bankovní zápisy na saldokontní
 * účet partnera nenesou a spadnou do „nespárovaného zbytku" (rozdíl konfrontace).
 * Partner dimenze přímo na journal_entry_lines je fáze 2 (mimo scope).
 *
 * Návratové hodnoty jsou SIGNED (debet mínus kredit, bez abs()) — orientaci na
 * normální stranu účtu (kladné = pohledávka/závazek) dělá až SaldoService, stejně
 * jako už dělá pro `gl_balance`. Díky tomu se dobropis (opačná strana účtu) v netto
 * součtu partnera správně ODEČÍTÁ, ne přičítá (post-review fix H1 — abs() by
 * dobropis proměnil v další kladnou pohledávku místo odpočtu).
 *
 * `paid_ratio` (0..1) je dopočten PER DOKLAD K ASOF ze zdroje pravdy platby daného typu
 * dokladu (post-review fix H2 — dřívější `payment_matches` pokrývalo jen bankovní
 * úhrady, hotovostní/ruční úhrady faktur zůstávaly nezapočtené a doklad vypadal
 * jako plně otevřený i po zaplacení):
 *   - invoices: suma `invoice_payments.amount` s `paid_on <= asOf` vůči
 *     `amount_to_pay` (obojí v MĚNĚ FAKTURY — poměr je tedy stejnoměnný,
 *     bez míchání CZK zaúčtované hodnoty s cizoměnovou platbou).
 *   - purchase_invoices: nemají obdobu `invoice_payments` — `status='paid'`
 *     je plně krytý až od `paid_at`
 *     (nastaví ho i hotovostní úhrada přes CashDocumentService::applySideEffects)
 *     = plně kryto; jinak se poměr skládá ze Σ `payment_matches.amount` (banka),
 *     Σ pokladních dokladů a Σ obou ZÁPOČTOVÝCH cest k asOf ({@see \MyInvoice\Support\Sql\PurchaseSettledExpr::offsetSettledAsOf}
 *     — `offset_agreement_items` a `invoice_settlements`). Na `status='paid'` se
 *     u zápočtu spolehnout NELZE: doúčtování zápočtu bez účetní stopy stav dokladu
 *     záměrně nepřestavuje (`InvoiceSettlementService::postRow`) a ČÁSTEČNÝ zápočet
 *     doklad na `paid` nepřeklápí vůbec — bez téhle složky by vyrovnaná faktura
 *     svítila jako celá otevřená a částečně započtená celou částkou místo zbytkem.
 *     Ani `status='paid'` není bezpodmínečný: když k dokladu úhrady EXISTUJÍ a nechávají
 *     na saldokontě zbytek nad {@see SHORTFALL_TOLERANCE_CZK}, jde o nedoplatek a doklad
 *     zůstává otevřený svým zbytkem (dřív ho ruční párování s nižší platbou tiše
 *     uzavřelo a saldo zbytek na 321 schovalo).
 *     `payment_matches.amount` je v MĚNĚ TRANSAKCE; do měny PF ho převádí
 *     {@see \MyInvoice\Support\Sql\PurchaseSettledExpr::bankAmountSql()} (dříve KNOWN GAP H3).
 *     Nepřevoditelná kombinace (cizí měna pohybu × jiná měna dokladu) zůstává nepřesná
 *     a projeví se jako rozdíl konfrontace.
 *
 * DATUM VYROVNÁNÍ musí být totéž, které zná HLAVNÍ KNIHA (jinak konfrontace nesedí):
 * u bankovních úhrad se proto NEBERE `invoice_payments.paid_on` / `purchase_invoices.paid_at`
 * (den z dokladu, u legacy importů běžně den vystavení faktury), ale `entry_date`
 * ZAÚČTOVANÉHO bankovního zápisu, s fallbackem na `bank_transactions.posted_at` a teprve
 * pak na doklad. Lednový výpis k prosincové faktuře tak fakturu k 31. 12. NESKRYJE —
 * HK ji k tomu dni také má otevřenou. Nezaúčtovaný výpis fallbackem propadne na datum
 * pohybu, takže se v konfrontaci projeví jako ROZDÍL — což je žádoucí, je to nález
 * („banka není zaúčtovaná"), ne chyba sestavy.
 *
 * ZÁLOHA INKASOVANÁ PŘÍMO NA SALDOKONTNÍ ÚČET (bez 324/314) — viz
 * {@see advanceOnAccountCte}: proforma je samostatný doklad a platba míří na NI, ne na
 * finální fakturu; když ji účetní účtuje rovnou na 311, vypadá plně předplacená faktura
 * jako celá otevřená. Agregace proto přičte takovou zálohu do čitatele i jmenovatele
 * poměru — a protože je podmíněná tím, že úhrada zálohy dopadla NA TENHLE účet, tenantů
 * používajících 324/314 se nedotkne. Dokud konečná faktura neexistuje, drží hlavní kniha
 * inkaso na účtu jako přijatou zálohu a saldokonto ji ukazuje jako zápornou položku
 * proformy ({@see fetchReceivedAdvances} s `$onReceivable`). Zrcadlově poskytnutá záloha
 * placená přímo na 321 ({@see fetchPaidAdvances} s `$onPayable`).
 *
 * Storno (H4): dřívější filtr `reversed_by IS NULL` odrážel AKTUÁLNÍ stav, ne stav
 * K ASOF — doklad stornovaný AŽ PO rozvahovém dni by k asOf zmizel ze seznamu,
 * přestože k tomu dni byl v hlavní knize ještě živý. Filtrujeme proto podle
 * `entry_date` protizápisu (storno platí, jen když jeho zápis má
 * `entry_date <= asOf`), shodně s tím, jak časovou platnost storna řeší hlavní
 * kniha (LedgerReportRepository počítá vše přes `entry_date`, ne přes flag).
 *
 * Storno RUČNÍM protidokladem (H4b): doklad může být účetně vyrušen i zrcadlovým
 * RUČNÍM zápisem (source_type='manual'), který VĚDOMĚ nechává původní kontaci živou
 * (oba zápisy live → HK i VH netují na 0, `reversed_by` se ZÁMĚRNĚ nenastavuje, aby
 * dotazy `reversed_by IS NULL` nevyloučily jen jednu stranu a nerozbily VH). Takový
 * protidoklad ale nenese `source_type='invoice'/'purchase_invoice'`, takže se na úrovni
 * DOKLADU s původní fakturou v saldu neztuluje a faktura by svítila jako otevřená.
 * Řešíme ČASOVĚ UVĚDOMĚLE přes `cancelled_at` jako den vyrovnání (settlement date):
 * stornovaný doklad je otevřený k asOf < cancelled_at a uzavřený k asOf >= cancelled_at.
 * Filtr je pro NORMÁLNĚ stornované doklady no-op — ty už vyloučil `reversed_by` výše
 * (běžný storno reverzuje kontaci k `entry_date` originálu, tedy DŘÍV než cancelled_at),
 * takže bije jen na ručně vyrovnané anomálie, kde je kontace živá. `cancelled_at IS NULL`
 * (H4 scénář reverzí bez timestampu) filtr nechává beze změny — vyřeší ho `reversed_by`.
 */
final class SaldoRepository
{
    /**
     * Bezpečnostní pásmo SQL filtru otevřenosti (viz {@see openFilterSql}). Je ZÁMĚRNĚ
     * menší než haléřový práh 0,005, kterým rozhoduje `SaldoService::buildAccount()` —
     * SQL tak vrací nadmnožinu otevřených položek a poslední slovo má pořád PHP.
     */
    private const OPEN_EPSILON = '0.0045';
    /**
     * Zbytek na saldokontě (Kč), nad kterým „uhrazená" přijatá faktura se zaznamenanou
     * úhradou přestává být plně krytá. Táž koruna jako dorovnání 548/648 v bance
     * (`BankPostingService::ROUNDING_TOLERANCE_CENTS`) a uzávěrková kontrola
     * `ClosingRepository::paidPurchasesOpenSaldo()` — co banka dorovná, saldo neukáže,
     * a co kontrola hlásí, saldo neschová.
     */
    private const SHORTFALL_TOLERANCE_CZK = 1.0;
    private const CANDIDATE_PAGE_SIZE = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly int $candidatePageSize = self::CANDIDATE_PAGE_SIZE,
    ) {
        if ($candidatePageSize < 1 || $candidatePageSize > self::CANDIDATE_PAGE_SIZE) {
            throw new \InvalidArgumentException('Neplatná velikost dávky saldokonta.');
        }
    }

    /**
     * Syntetický (nebo listový) účet firmy dle kódu. Vrací i normal_side a typ pro
     * určení strany zůstatku. NULL = účet v osnově firmy neexistuje.
     *
     * @return array{id:int, code:string, name:string, account_type:string, normal_side:?string}|null
     */
    public function resolveAccount(int $supplierId, string $code, ?string $asOf = null): ?array
    {
        $relation=AccountDefinitionRepository::relation($asOf??date('Y-m-d'),$supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name, account_type, normal_side
               FROM {$relation} a
              WHERE supplier_id = ? AND account_code = ?
              ORDER BY is_synthetic DESC, id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'code'         => (string) $row['account_code'],
            'name'         => (string) $row['name'],
            'account_type' => (string) $row['account_type'],
            'normal_side'  => $row['normal_side'] === null ? null : (string) $row['normal_side'],
        ];
    }

    /**
     * Otevřené položky účtu k rozvahovému dni: faktury (vydané + přijaté) zaúčtované
     * na daný účet (vč. analytik pod syntetikou) s vazbou přes source_type/source_id.
     * `booked_signed`/`foreign_signed` = SUM(debit − credit) za doklad, SIGNED (viz
     * doc-komentář třídy). `paid_ratio` viz tamtéž.
     *
     * Uzavřené položky (netto remaining = 0) odfiltruje UŽ SQL ({@see openFilterSql}) —
     * bez toho se do PHP tahal každý doklad, který firma kdy na 311/321 zaúčtovala,
     * a sestava padala na `memory_limit` (1 mil. dokladů ≈ 750 MB) dřív, než se
     * vůbec dostala k filtru. SQL filtr je jeho NADMNOŽINA; definitivní haléřový
     * filtr proto repository aplikuje při stránkování kandidátů a stejný veřejný
     * výpočet používají i obě navazující služby.
     *
     * DOLNÍ HRANICE DATA tu ZÁMĚRNĚ NENÍ (a nesmí být): saldo je kumulativní veličina
     * „k datu". Otevřenou položkou k 31. 12. 2026 může být faktura z roku 2019, kterou
     * nikdo nezaplatil — jakékoli „ber jen posledních N let" by ji ze seznamu vyhodilo,
     * Σ otevřených položek by přestala odpovídat zůstatku hlavní knihy (ten dolní
     * hranici nemá) a rozdíl by se vykázal jako inventarizační nález. Zdola omezit lze
     * až tehdy, kdyby se sestava počítala od zůstatku po uzávěrce, což by ale zrušila
     * možnost saldokonta k libovolnému dni napříč obdobími. Objem místo toho řeší
     * filtr otevřenosti (uzavřené položky se nenačtou) a strop s odmítnutím.
     *
     * @param int|null $limit maximální počet vrácených řádků (NULL = bez stropu).
     *                        Volající si vyžádá o jeden víc, než smí zobrazit, a podle
     *                        přetečení pozná, že je sestava nad stropem — viz
     *                        `SaldoService::MAX_OPEN_ITEMS`.
     * @param int|null $partnerId filtr na jednoho partnera. Musí být v SQL, ne až v PHP:
     *                        s `$limit` by se strop vyčerpal na cizích partnerech a
     *                        položky toho hledaného by tiše vypadly.
     * @param string|null $dueBefore volitelná horní hranice splatnosti (exclusive),
     *                        aplikovaná v SQL před stránkováním.
     * @param bool $orderByDue řadit kandidáty primárně od nejstarší splatnosti.
     * @return list<array{doc_type:string, doc_id:int, doc_no:string, issue_date:string,
     *                    due_date:string, status:string, partner_id:int, partner_name:string,
     *                    currency_code:string, booked_signed:float, foreign_signed:float,
     *                    paid_ratio:float}>
     */
    public function openItems(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?string $accountCode = null,
        ?int $limit = null,
        ?int $partnerId = null,
        ?string $dueBefore = null,
        bool $orderByDue = false,
    ): array {
        $pdo = $this->db->pdo();
        $ownsSnapshot = !$pdo->inTransaction();
        if ($ownsSnapshot) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
        }

        try {
            if ($accountCode === '324') {
                $result = array_merge(
                    $this->fetchReceivedAdvances($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                    $this->fetchOpenOtherItems($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                );
            } elseif ($accountCode === '314') {
                $result = array_merge(
                    $this->fetchPaidAdvances($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                    $this->fetchOpenOtherItems($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                );
            } else {
                $result = array_merge(
                    $this->fetchOpenInvoices($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                    // Přijatá záloha inkasovaná rovnou na tenhle účet a ještě nevyúčtovaná
                    // konečnou fakturou. Není to pohledávka po splatnosti, proto ji přehled
                    // neuhrazených dokladů (volá s $dueBefore) nedostane.
                    $dueBefore === null
                        ? $this->fetchReceivedAdvances($supplierId, $accountId, $asOf, $limit, $partnerId, null, $orderByDue, onReceivable: true)
                        : [],
                    $this->fetchOpenPurchases($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                    $dueBefore === null
                        ? $this->fetchPaidAdvances($supplierId, $accountId, $asOf, $limit, $partnerId, null, $orderByDue, onPayable: true)
                        : [],
                    $this->fetchOpenOtherItems($supplierId, $accountId, $asOf, $limit, $partnerId, $dueBefore, $orderByDue),
                );
            }
            if ($ownsSnapshot) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsSnapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchOpenOtherItems(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?int $limit,
        ?int $partnerId,
        ?string $dueBefore,
        bool $orderByDue,
    ): array {
        $booked = "ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 2)";
        $paid = 'COALESCE(paid.paid_sum, 0)';
        $ratio = "CASE WHEN ABS({$booked}) < 0.005 THEN 0
                       ELSE LEAST(1, GREATEST(0, {$paid} / ABS({$booked}))) END";
        $partnerFilter = $partnerId === null ? '' : ' AND oi.partner_id = ' . $partnerId;
        $dueFilter = self::dueBeforeSql('oi', $dueBefore, 'due_on');
        $order = $orderByDue
            ? ' ORDER BY oi.due_on, oi.id'
            : ' ORDER BY partner_name, oi.due_on, oi.id';
        $sql = "SELECT oi.id AS doc_id, COALESCE(oi.document_no, CONCAT('#', oi.id)) AS doc_no,
                       oi.issued_on AS issue_date, oi.due_on AS due_date, oi.status,
                       COALESCE(oi.partner_id, -CAST(oi.id AS SIGNED)) AS partner_id,
                       COALESCE(cl.company_name, NULLIF(oi.partner_name, ''), 'Bez partnera') AS partner_name,
                       oi.currency AS currency_code, oi.amount AS amount_foreign,
                       {$booked} AS booked_signed, {$paid} AS paid_as_of
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                  JOIN other_items oi ON oi.id = e.source_id AND oi.supplier_id = e.supplier_id
                  LEFT JOIN clients cl ON cl.id = oi.partner_id AND cl.supplier_id = oi.supplier_id
                  LEFT JOIN (
                    SELECT a.other_item_id, SUM(a.amount) AS paid_sum
                      FROM other_item_allocations a
                      JOIN other_items payment_item ON payment_item.id = a.other_item_id
                           AND payment_item.supplier_id = a.supplier_id
                     WHERE a.supplier_id = {$supplierId}
                       AND (a.reversed_on IS NULL OR a.reversed_on > ?)
                       AND EXISTS (
                           SELECT 1 FROM journal_entries pe
                           JOIN journal_entry_lines pl ON pl.entry_id = pe.id AND pl.supplier_id = pe.supplier_id
                           LEFT JOIN journal_entries prev ON prev.id = pe.reversed_by
                          WHERE pe.supplier_id = a.supplier_id
                            AND pe.source_type = CASE WHEN a.bank_transaction_id IS NOT NULL THEN 'bank' ELSE 'cash' END
                            AND pe.source_id = COALESCE(a.bank_transaction_id, a.cash_document_id)
                            AND pe.posted_at IS NOT NULL AND pe.entry_date <= ?
                            AND (pe.reversed_by IS NULL OR prev.entry_date > ?)
                            AND pl.side = CASE WHEN payment_item.side = 'receivable' THEN 'credit' ELSE 'debit' END
                            AND EXISTS (
                                SELECT 1 FROM journal_entries item_entry
                                JOIN journal_entry_lines item_line ON item_line.entry_id = item_entry.id
                                     AND item_line.supplier_id = item_entry.supplier_id
                                 WHERE item_entry.supplier_id = payment_item.supplier_id
                                   AND item_entry.source_type = 'other_item'
                                   AND item_entry.source_id = payment_item.id
                                   AND item_entry.posted_at IS NOT NULL
                                   AND item_line.account_id = pl.account_id
                                   AND item_line.side = CASE WHEN payment_item.side = 'receivable' THEN 'debit' ELSE 'credit' END
                            )
                       )
                     GROUP BY a.other_item_id
                  ) paid ON paid.other_item_id = oi.id
                 WHERE e.supplier_id = {$supplierId} AND e.source_type = 'other_item'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
                   AND l.side = CASE WHEN oi.side = 'receivable' THEN 'debit' ELSE 'credit' END
                   AND oi.deleted_at IS NULL
                   {$partnerFilter}{$dueFilter}
                 GROUP BY oi.id, oi.document_no, oi.issued_on, oi.due_on, oi.status,
                          oi.partner_id, cl.company_name, oi.partner_name, oi.currency, oi.amount, paid.paid_sum
                HAVING " . self::openFilterSql($booked, $ratio) . $order;

        return $this->fetchDefinitiveOpenRows(
            $sql,
            static fn (string $pageSql): array => self::asOfParams($pageSql, $asOf),
            function (array $row): array {
                $booked = round((float) $row['booked_signed'], 2);
                $paid = (float) $row['paid_as_of'];
                return [
                    'doc_type' => 'other_item',
                    'doc_id' => (int) $row['doc_id'],
                    'doc_no' => (string) $row['doc_no'],
                    'issue_date' => (string) $row['issue_date'],
                    'due_date' => (string) $row['due_date'],
                    'status' => (string) $row['status'],
                    'partner_id' => (int) $row['partner_id'],
                    'partner_name' => (string) $row['partner_name'],
                    'currency_code' => (string) $row['currency_code'],
                    'booked_signed' => $booked,
                    'foreign_signed' => $row['currency_code'] === 'CZK' ? 0.0
                        : ($booked < 0 ? -1 : 1) * (float) $row['amount_foreign'],
                    'paid_ratio' => abs($booked) < 0.005 ? 0.0 : min(1.0, max(0.0, $paid / abs($booked))),
                ];
            },
            $limit,
        );
    }

    /** `AND cl.id = <id>` (int literál, viz fetchOpenInvoices) nebo prázdný řetězec. */
    private static function partnerSql(?int $partnerId): string
    {
        return $partnerId === null ? '' : ' AND cl.id = ' . $partnerId;
    }

    /**
     * Hodnoty pro placeholdery dotazů, kde je jediným parametrem `asOf` (viz komentář
     * u {@see fetchOpenInvoices} o literálních ID). Počet se bere ze SQL, takže se
     * parametry nemohou rozejít s dotazem.
     *
     * @return list<string>
     */
    private static function asOfParams(string $sql, string $asOf): array
    {
        return array_fill(0, substr_count($sql, '?'), $asOf);
    }

    /**
     * SQL protějšek filtru `SaldoService::buildAccount()` („zbytek v haléřích = 0 ⇒
     * položka je uzavřená"). Platí `remaining = ROUND(booked × (1 − ratio), 2)`, protože
     * `booked` je už na haléřové mřížce a odečtení mřížkové hodnoty se zaokrouhlením
     * komutuje; k uzavření tedy stačí `|booked × (1 − ratio)| < 0,005`.
     *
     * Práh je tu ZÁMĚRNĚ nižší ({@see OPEN_EPSILON} = 0,0045): v pásmu ⟨0,0045; 0,005)
     * a na půlhaléřových remízách (kde se komutace zaokrouhlení rozchází o haléř) SQL
     * řádek raději PUSTÍ a zahodí ho až PHP. Filtr je proto prokazatelná NADMNOŽINA
     * toho PHP — nikdy nezahodí položku, kterou by sestava zobrazila.
     *
     * @param string $booked SQL výraz se signed zaúčtovanou částkou (zaokrouhlenou na 2 des. místa)
     * @param string $ratio  SQL výraz s poměrem uhrazeno/celkem v ⟨0;1⟩
     */
    private static function openFilterSql(string $booked, string $ratio): string
    {
        return 'ABS(' . $booked . ' * (1 - (' . $ratio . '))) >= ' . self::OPEN_EPSILON;
    }

    /**
     * SQL protějšek {@see paidRatio()} bez zkratky „plně uhrazeno" — clamp do ⟨0;1⟩
     * a haléřová ochrana proti dělení nulovým `amount_to_pay`.
     */
    private static function paidRatioSql(string $paidSignal, string $amountToPay): string
    {
        return "CASE WHEN ABS({$amountToPay}) < 0.005 THEN 0
                     ELSE LEAST(1, GREATEST(0, ({$paidSignal}) / ({$amountToPay}))) END";
    }

    /**
     * Filtr otevřenosti pro zálohové účty (324/314), kde otevřenou položkou není faktura,
     * ale ZAÚČTOVANÝ POHYB peněz snížený o čerpání. Poměr se počítá z pohybu, ne z
     * `amount_to_pay` — proto vlastní varianta {@see paidRatioSql}: `$movement <= 0`
     * znamená (shodně s PHP) nulový poměr, ne dělení záporným jmenovatelem.
     */
    private static function advanceOpenFilterSql(string $movement, string $settled): string
    {
        $m = "ROUND({$movement}, 2)";
        $s = "ROUND({$settled}, 2)";

        return self::openFilterSql($m, "CASE WHEN {$m} > 0 THEN LEAST(1, GREATEST(0, {$s} / {$m})) ELSE 0 END");
    }

    /**
     * `EXISTS`: vydaný doklad má k `x.as_of` živý zaúčtovaný předpis na účtu `x.account_id`
     * (vč. analytik). Časovou platnost měří stejně jako hlavní kniha, tj. `entry_date`
     * zápisu a jeho případného protizápisu. Vyžaduje v dotazu alias `x` z CTE `params`.
     */
    private static function invoiceBookedOnAccountSql(string $invoiceIdExpr): string
    {
        return self::bookedOnAccountSql('invoice', $invoiceIdExpr);
    }

    /** Zrcadlo {@see invoiceBookedOnAccountSql} pro přijatý doklad. */
    private static function purchaseBookedOnAccountSql(string $purchaseIdExpr): string
    {
        return self::bookedOnAccountSql('purchase_invoice', $purchaseIdExpr);
    }

    private static function bookedOnAccountSql(string $sourceType, string $docIdExpr): string
    {
        return "EXISTS (
                   SELECT 1
                     FROM journal_entries be
                     JOIN journal_entry_lines bl ON bl.entry_id = be.id AND bl.supplier_id = be.supplier_id
                     JOIN chart_of_accounts bca ON bca.id = bl.account_id
                     LEFT JOIN journal_entries brev ON brev.id = be.reversed_by
                    WHERE be.supplier_id = x.supplier_id
                      AND be.source_type = '{$sourceType}' AND be.source_id = {$docIdExpr}
                      AND be.posted_at IS NOT NULL AND be.entry_date <= x.as_of
                      AND (be.reversed_by IS NULL OR brev.entry_date > x.as_of)
                      AND (bca.id = x.account_id OR bca.parent_id = x.account_id)
               )";
    }

    private static function dueBeforeSql(string $alias, ?string $dueBefore, string $column = 'due_date'): string
    {
        if ($dueBefore === null) {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueBefore) !== 1) {
            throw new \InvalidArgumentException('saldo_due_date_invalid');
        }
        return " AND {$alias}.{$column} < '{$dueBefore}'";
    }

    private static function orderSql(string $alias, bool $orderByDue): string
    {
        return $orderByDue
            ? " ORDER BY {$alias}.due_date, {$alias}.id, cl.company_name"
            : " ORDER BY cl.company_name, {$alias}.due_date, {$alias}.id";
    }

    private static function limitSql(int $limit, int $offset): string
    {
        return ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }

    /**
     * @return array{paid:float, remaining:float, open:bool}
     */
    public static function settlementAmounts(float $bookedAmount, float $paidRatio): array
    {
        $paid = round($bookedAmount * $paidRatio, 2);
        $remaining = round($bookedAmount - $paid, 2);
        return [
            'paid' => $paid,
            'remaining' => $remaining,
            'open' => (int) round($remaining * 100.0) !== 0,
        ];
    }

    /**
     * SQL filtr vrací záměrnou nadmnožinu. Tato smyčka plní volající limit až po
     * definitivním haléřovém filtru a drží každý SQL batch paměťově ohraničený.
     *
     * @param callable(string):list<mixed> $params
     * @param callable(array<string,mixed>):array<string,mixed> $map
     * @return list<array<string,mixed>>
     */
    private function fetchDefinitiveOpenRows(string $sql, callable $params, callable $map, ?int $limit): array
    {
        return iterator_to_array($this->iterateDefinitiveOpenRows($sql, $params, $map, $limit), false);
    }

    private function iterateDefinitiveOpenRows(string $sql, callable $params, callable $map, ?int $limit, bool $currentRead = false, bool $componentsOnly = false): \Generator
    {
        $pageSize = $limit === null
            ? $this->candidatePageSize
            : min($this->candidatePageSize, max(1, $limit));
        $offset = 0;
        $count = 0;
        do {
            $pageSql = $sql . self::limitSql($pageSize, $offset) . ($currentRead ? ' FOR UPDATE' : '');
            $stmt = $this->db->pdo()->prepare($pageSql);
            $stmt->execute($params($pageSql));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            foreach ($rows as $row) {
                $item = $map($row);
                if (!$componentsOnly && !self::settlementAmounts((float) $item['booked_signed'], (float) $item['paid_ratio'])['open']) {
                    continue;
                }
                yield $item;
                if ($limit !== null && ++$count >= $limit) {
                    return;
                }
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);
    }

    public function settlementComponents(int $supplierId, string $docType, int $docId, int $accountId, string $asOf, bool $currentRead = false): ?array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current settlement reads require caller period-first locks and a transaction.');
        $rows = match ($docType) {
            'invoice' => iterator_to_array($this->fetchOpenInvoices($supplierId, $accountId, $asOf, null, stream: true, invoiceIds: [$docId], currentRead: $currentRead, componentsOnly: true), false),
            'purchase_invoice' => $this->fetchOpenPurchases($supplierId, $accountId, $asOf, null, currentRead: $currentRead, componentsOnly: true, documentId: $docId),
            default => throw new \InvalidArgumentException('Invalid settlement document type.'),
        };
        if (count($rows) > 1) throw new \LogicException('Ambiguous document settlement components.');
        return $rows[0] ?? null;
    }

    public function iterateOpenInvoiceItems(int $supplierId, int $accountId, string $asOf, ?array $invoiceIds = null, bool $currentRead = false): \Generator
    {
        if ($invoiceIds === []) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($currentRead && !$pdo->inTransaction()) throw new \LogicException('Current saldo reads require caller period-first locks and a transaction.');
        $ownsSnapshot = !$pdo->inTransaction();
        if ($ownsSnapshot) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
        }
        try {
            if ($invoiceIds === null) {
                yield from $this->fetchOpenInvoices($supplierId, $accountId, $asOf, null, stream: true, currentRead: $currentRead);
            } else {
                foreach (array_chunk(array_values(array_unique(array_map('intval', $invoiceIds))), 500) as $chunk) {
                    yield from $this->fetchOpenInvoices($supplierId, $accountId, $asOf, null, stream: true, invoiceIds: $chunk, currentRead: $currentRead);
                }
            }
        } finally {
            if ($ownsSnapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Den, ke kterému HLAVNÍ KNIHA uznává bankovní pohyb: `entry_date` zaúčtovaného
     * bankovního zápisu, jehož případný protizápis platí až PO asOf. Právě tenhle den,
     * ne `paid_at`/`paid_on` z dokladu, řídí, kdy z účtu 311/321 mizí pohledávka/závazek —
     * konfrontace se zůstatkem HK proto musí počítat se stejným datem. Volající si
     * doplňuje fallback na `DATE(bt.posted_at)` (nezaúčtovaný výpis), u vydaných faktur
     * ještě na `ip.paid_on`.
     *
     * Dřív šlo o KORELOVANÝ poddotaz vyhodnocovaný na každý řádek platby (a ten byl
     * navíc uvnitř dalšího poddotazu na každý řádek sestavy); teď je to jedna agregace
     * přes bankovní zápisy firmy. Obsahuje JEDEN placeholder: asOf.
     */
    private static function bankSettleCte(int $supplierId, bool $currentRead = false): string
    {
        return
            "SELECT be.source_id AS bank_transaction_id, MIN(be.entry_date) AS settled_on
               FROM journal_entries be
               LEFT JOIN journal_entries brev ON brev.id = be.reversed_by
              WHERE be.supplier_id = {$supplierId} AND be.source_type = 'bank' AND be.posted_at IS NOT NULL
                AND (be.reversed_by IS NULL OR brev.entry_date > ?)
              GROUP BY be.source_id" . ($currentRead ? ' FOR UPDATE' : '');
    }

    /**
     * Přijaté zálohy na 324 vznikají peněžním zápisem banky/pokladny, nikoli
     * předpisem proformy. Otevřenou položkou je proto zaúčtované inkaso proformy
     * snížené o čerpání 324 z DDKP a vyúčtovacích faktur navázaných přes
     * parent_invoice_id. Odvození jen z invoice journalu by ukázalo samotné čerpání
     * jako zápornou otevřenou položku a zcela minulo původní 221/324.
     *
     * `$onReceivable` = stejná záloha, jen inkasovaná PŘÍMO na saldokontní účet
     * (pravidlo `advance.received.collection` s 311 místo 324). Inkaso 221 MD / 311 D
     * nemá na 311 žádný doklad, ke kterému by patřilo, a DDKP k němu (311 MD / 343 D)
     * uzavře {@see advanceOnAccountCte} jako předplacený. Hlavní kniha přitom na 311
     * drží přijatou zálohu bez DPH, dokud nevznikne konečná faktura — saldokonto ji
     * dřív neukázalo vůbec a konfrontace hlásila rozdíl ve výši zálohy. Položkou je
     * proto inkaso snížené o čistý pohyb DDKP na účtu, a to jen do chvíle, kdy k asOf
     * existuje zaúčtovaná konečná faktura: od té doby zálohu vypořádává její řádek
     * poměrem v {@see fetchOpenInvoices} (čitatel i jmenovatel nesou zálohu), takže by
     * se tu započetla podruhé. Proforma s vlastním předpisem na účtu se nebere, tu
     * vyrovnávají její vlastní úhrady už ve {@see fetchOpenInvoices}.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchReceivedAdvances(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?int $limit,
        ?int $partnerId = null,
        ?string $dueBefore = null,
        bool $orderByDue = false,
        bool $onReceivable = false,
    ): array
    {
        if ($onReceivable) {
            $settledAmount = "CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END";
            $settledFilter = "AND child.invoice_type = 'tax_document'";
            $receivableFilter = '
               AND NOT ' . self::invoiceBookedOnAccountSql('p.id') . "
               AND NOT EXISTS (
                   SELECT 1 FROM invoices fin
                    WHERE fin.supplier_id = p.supplier_id AND fin.parent_invoice_id = p.id
                      AND fin.invoice_type NOT IN ('proforma', 'tax_document')
                      AND " . self::invoiceBookedOnAccountSql('fin.id') . '
               )';
        } else {
            $settledAmount = 'l.signed_amount';
            $settledFilter = "AND l.side = 'debit'";
            $receivableFilter = '';
        }

        $sql =
            "WITH params AS (
                SELECT CAST(? AS UNSIGNED) AS supplier_id,
                       CAST(? AS DATE) AS as_of,
                       CAST(? AS UNSIGNED) AS account_id
            ), collected AS (
                SELECT ip.invoice_id, SUM(ip.amount) AS collected_czk
                  FROM invoice_payments ip
                  CROSS JOIN params x
                  JOIN invoices ipf ON ipf.id = ip.invoice_id AND ipf.supplier_id = ip.supplier_id
                                   AND ipf.invoice_type = 'proforma'
                 WHERE ip.supplier_id = x.supplier_id AND ip.paid_on <= x.as_of
                   AND (
                       EXISTS (
                           SELECT 1
                             FROM journal_entries e
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                             JOIN chart_of_accounts ca ON ca.id = l.account_id
                             LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                            WHERE ip.bank_transaction_id IS NOT NULL
                              AND e.supplier_id = ip.supplier_id
                              AND e.source_type = 'bank' AND e.source_id = ip.bank_transaction_id
                              AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                              AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                              AND l.side = 'credit'
                              AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                       )
                       OR EXISTS (
                           SELECT 1
                             FROM cash_documents cd
                             JOIN journal_entries e
                               ON e.supplier_id = cd.supplier_id AND e.source_type = 'cash' AND e.source_id = cd.id
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                             JOIN chart_of_accounts ca ON ca.id = l.account_id
                             LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                            WHERE cd.supplier_id = ip.supplier_id AND cd.invoice_payment_id = ip.id
                              AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                              AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                              AND l.side = 'credit'
                              AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                       )
                   )
                 GROUP BY ip.invoice_id
            ), settled AS (
                SELECT child.parent_invoice_id AS invoice_id, SUM({$settledAmount}) AS settled_czk
                  FROM invoices child
                  CROSS JOIN params x
                  JOIN journal_entries e
                    ON e.supplier_id = child.supplier_id AND e.source_type = 'invoice' AND e.source_id = child.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE child.supplier_id = x.supplier_id AND child.parent_invoice_id IS NOT NULL
                   AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                   AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                   {$settledFilter}
                   AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                 GROUP BY child.parent_invoice_id
            )
            SELECT p.id AS doc_id,
                   COALESCE(NULLIF(p.varsymbol, ''), CONCAT('#', p.id)) AS doc_no,
                   p.issue_date, p.due_date, p.status,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   c.collected_czk, COALESCE(s.settled_czk, 0) AS settled_czk,
                   (SELECT td.id FROM invoices td
                     WHERE td.supplier_id = p.supplier_id AND td.parent_invoice_id = p.id
                       AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')
                     ORDER BY td.id LIMIT 1) AS tax_document_id
              FROM collected c
              JOIN invoices p ON p.id = c.invoice_id
              JOIN params x ON x.supplier_id = p.supplier_id
              JOIN clients cl ON cl.id = p.client_id
              JOIN currencies cur ON cur.id = p.currency_id
              LEFT JOIN settled s ON s.invoice_id = p.id
             WHERE p.invoice_type = 'proforma'
               AND " . self::advanceOpenFilterSql('c.collected_czk', 'COALESCE(s.settled_czk, 0)')
               . $receivableFilter
               . self::partnerSql($partnerId)
               . self::dueBeforeSql('p', $dueBefore)
               . self::orderSql('p', $orderByDue);

        return $this->fetchDefinitiveOpenRows(
            $sql,
            static fn (string $pageSql): array => [$supplierId, $asOf, $accountId],
            static function (array $r) use ($onReceivable): array {
                $collected = round((float) $r['collected_czk'], 2);
                $settled = round((float) $r['settled_czk'], 2);
                return [
                    // Záloha na saldokontním účtu čekající na vyúčtování — sestava ji musí
                    // popsat jinak než fakturu (částka = přijatá platba, uhrazeno = daň DDKP).
                    'kind'           => $onReceivable ? 'advance_pending' : 'document',
                    'tax_document_id' => $r['tax_document_id'] !== null ? (int) $r['tax_document_id'] : null,
                    'doc_type'       => 'invoice',
                    'doc_id'         => (int) $r['doc_id'],
                    'doc_no'         => (string) $r['doc_no'],
                    'issue_date'     => (string) $r['issue_date'],
                    'due_date'       => (string) $r['due_date'],
                    'status'         => (string) $r['status'],
                    'partner_id'     => (int) $r['partner_id'],
                    'partner_name'   => (string) $r['partner_name'],
                    'currency_code'  => (string) $r['currency_code'],
                    'booked_signed'  => -$collected,
                    'foreign_signed' => 0.0,
                    'paid_ratio'     => $collected > 0.0 ? min(1.0, max(0.0, $settled / $collected)) : 0.0,
                ];
            },
            $limit,
        );
    }

    /**
     * Poskytnuté zálohy na 314: bankovní/pokladní platba zálohové přijaté
     * faktury snížená o zúčtování 321/314 z finální přijaté faktury.
     *
     * Otevřenou položkou je i SAMOSTATNÝ DDKP (`tax_document` BEZ zálohové faktury) —
     * typicky nákup zaplacený kartou, kde prodejce vystaví jen „daňový doklad ke dni
     * přijaté úplaty" (§ 28/8) a fakturu pošle až s dodáním. `BankPostingService::
     * outgoingCounterAccount()` jeho úhradu ZÁMĚRNĚ účtuje na 314 (§ 20a/2), jenže
     * tahle metoda dřív brala jen `document_kind='advance'`, takže doklad ze saldokonta
     * vypadl CELÝ — debet z platby i vlastní kredit DPH. Hlavní kniha ho přitom měla,
     * takže konfrontace ukazovala rozdíl o zaplacenou částku sníženou o daň, aniž by
     * bylo z čeho poznat proč.
     *
     * `$onPayable` = zrcadlo {@see fetchReceivedAdvances} s `$onReceivable`: zálohová PF
     * zaplacená přímo na saldokontní účet (pravidlo `advance.paid.payment` s 321 místo 314).
     * Úhrada 321 MD / 221 D nemá na 321 doklad a přijatý DDKP k ní (343 MD / 321 D) uzavře
     * zkratka „uhrazeno" ve {@see fetchOpenPurchases}. Položkou je proto úhrada snížená
     * o čistý pohyb DDKP na účtu, dokud k asOf není zaúčtovaná konečná faktura (tu pak
     * vyrovnává {@see advanceOnAccountCte}). Záloha s vlastním předpisem na účtu se nebere
     * a samostatný DDKP taky ne: ten na 321 nese vlastní předpis i úhradu a vyrovná se sám.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchPaidAdvances(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?int $limit,
        ?int $partnerId = null,
        ?string $dueBefore = null,
        bool $orderByDue = false,
        bool $onPayable = false,
    ): array
    {
        if ($onPayable) {
            $settledAmount = "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END";
            $settledSide = '';
            $kindFilter = "p.document_kind = 'advance'"
                . ' AND NOT ' . self::purchaseBookedOnAccountSql('p.id') . "
               AND NOT EXISTS (
                   SELECT 1 FROM purchase_invoices fin
                    WHERE fin.supplier_id = p.supplier_id AND fin.advance_purchase_invoice_id = p.id
                      AND " . self::purchaseBookedOnAccountSql('fin.id') . '
               )';
            $links = "SELECT id AS child_id, supplier_id, parent_purchase_invoice_id AS advance_id
                        FROM purchase_invoices
                       WHERE document_kind = 'tax_document'
                         AND parent_purchase_invoice_id IS NOT NULL
                         AND advance_purchase_invoice_id IS NULL";
        } else {
            $settledAmount = 'l.signed_amount';
            $settledSide = "AND l.side = 'credit'";
            $kindFilter = "(p.document_kind = 'advance'
                    OR (p.document_kind = 'tax_document' AND p.parent_purchase_invoice_id IS NULL))";
            // Čerpání zálohy má TŘI vazební cesty a všechny musí do součtu:
            //   advance_purchase_invoice_id — vyúčtovací faktura (321 MD / 314 D),
            //   parent_purchase_invoice_id  — přijatý DDKP § 28 (343 MD / 314 D),
            //   samostatný DDKP bez rodiče  — čerpá SÁM SEBE (343 MD / 314 D).
            // DDKP první cestu použít NEMŮŽE: nad advance_purchase_invoice_id je
            // UNIQUE index (jedna záloha = jedna vyúčtovací faktura). Bez druhé větve
            // proto kredit DDKP na 314 vypadl a záloha svítila jako otevřená o celou
            // částku DPH navíc. Vydaná větev (324) tenhle problém nemá — používá
            // obecné parent_invoice_id IS NOT NULL, které chytí DDKP i finál.
            // Podmínka advance_purchase_invoice_id IS NULL v druhé větvi brání dvojímu
            // započtení, kdyby jeden doklad nesl obě vazby.
            // Samostatný DDKP je sám sobě zálohou i jejím čerpáním: na 314 mu sedí debet
            // z úhrady a kredit vlastní daně, zbytek (základ) čeká na konečnou fakturu. Bez
            // třetí větve by svítil jako otevřený o celou zaplacenou částku včetně daně.
            $links = "SELECT id AS child_id, supplier_id, advance_purchase_invoice_id AS advance_id
                        FROM purchase_invoices
                       WHERE advance_purchase_invoice_id IS NOT NULL
                      UNION ALL
                      SELECT id, supplier_id, parent_purchase_invoice_id
                        FROM purchase_invoices
                       WHERE document_kind = 'tax_document'
                         AND parent_purchase_invoice_id IS NOT NULL
                         AND advance_purchase_invoice_id IS NULL
                      UNION ALL
                      SELECT id, supplier_id, id
                        FROM purchase_invoices
                       WHERE document_kind = 'tax_document'
                         AND parent_purchase_invoice_id IS NULL
                         AND advance_purchase_invoice_id IS NULL";
        }

        $sql =
            "WITH params AS (
                SELECT CAST(? AS UNSIGNED) AS supplier_id,
                       CAST(? AS DATE) AS as_of,
                       CAST(? AS UNSIGNED) AS account_id
            ), paid AS (
                SELECT advance_id, SUM(paid_czk) AS paid_czk
                  FROM (
                      SELECT pm.purchase_invoice_id AS advance_id, pm.amount AS paid_czk
                        FROM payment_matches pm
                        CROSS JOIN params x
                        JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                        JOIN purchase_invoices ppi ON ppi.id = pm.purchase_invoice_id AND ppi.supplier_id = pm.supplier_id
                                                  AND ppi.document_kind IN ('advance', 'tax_document')
                       WHERE pm.supplier_id = x.supplier_id AND pm.purchase_invoice_id IS NOT NULL
                         AND DATE(bt.posted_at) <= x.as_of
                         AND EXISTS (
                             SELECT 1
                               FROM journal_entries e
                               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                               JOIN chart_of_accounts ca ON ca.id = l.account_id
                               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                              WHERE e.supplier_id = pm.supplier_id
                                AND e.source_type = 'bank' AND e.source_id = bt.id
                                AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                                AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                                AND l.side = 'debit'
                                AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                         )
                      UNION ALL
                      SELECT cd.purchase_invoice_id AS advance_id, cd.total_amount AS paid_czk
                        FROM cash_documents cd
                        CROSS JOIN params x
                        JOIN purchase_invoices cpi ON cpi.id = cd.purchase_invoice_id AND cpi.supplier_id = cd.supplier_id
                                                  AND cpi.document_kind IN ('advance', 'tax_document')
                       WHERE cd.supplier_id = x.supplier_id AND cd.purchase_invoice_id IS NOT NULL
                         AND cd.issue_date <= x.as_of
                         AND EXISTS (
                             SELECT 1
                               FROM journal_entries e
                               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                               JOIN chart_of_accounts ca ON ca.id = l.account_id
                               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                              WHERE e.supplier_id = cd.supplier_id
                                AND e.source_type = 'cash' AND e.source_id = cd.id
                                AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                                AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                                AND l.side = 'debit'
                                AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                         )
                  ) movements
                 GROUP BY advance_id
            ), settled AS (
                SELECT link.advance_id, SUM({$settledAmount}) AS settled_czk
                  FROM (
                      {$links}
                  ) link
                  CROSS JOIN params x
                  JOIN journal_entries e
                    ON e.supplier_id = link.supplier_id
                   AND e.source_type = 'purchase_invoice' AND e.source_id = link.child_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE link.supplier_id = x.supplier_id
                   AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                   AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                   {$settledSide}
                   AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                 GROUP BY link.advance_id
            )
            SELECT p.id AS doc_id,
                   COALESCE(NULLIF(p.vendor_invoice_number, ''), NULLIF(p.varsymbol, ''), CONCAT('#', p.id)) AS doc_no,
                   p.issue_date, p.due_date, p.status,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   paid.paid_czk, COALESCE(s.settled_czk, 0) AS settled_czk,
                   (SELECT td.id FROM purchase_invoices td
                     WHERE td.supplier_id = p.supplier_id AND td.parent_purchase_invoice_id = p.id
                       AND td.document_kind = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')
                     ORDER BY td.id LIMIT 1) AS tax_document_id
              FROM paid
              JOIN purchase_invoices p ON p.id = paid.advance_id
              JOIN params x ON x.supplier_id = p.supplier_id
              JOIN clients cl ON cl.id = p.vendor_id
              JOIN currencies cur ON cur.id = p.currency_id
              LEFT JOIN settled s ON s.advance_id = p.id
             WHERE {$kindFilter}
               AND " . self::advanceOpenFilterSql('paid.paid_czk', 'COALESCE(s.settled_czk, 0)')
               . self::partnerSql($partnerId)
               . self::dueBeforeSql('p', $dueBefore)
               . self::orderSql('p', $orderByDue);

        return $this->fetchDefinitiveOpenRows(
            $sql,
            static fn (string $pageSql): array => [$supplierId, $asOf, $accountId],
            static function (array $r) use ($onPayable): array {
                $paid = round((float) $r['paid_czk'], 2);
                $settled = round((float) $r['settled_czk'], 2);
                return [
                    'kind'           => $onPayable ? 'advance_pending' : 'document',
                    'tax_document_id' => $r['tax_document_id'] !== null ? (int) $r['tax_document_id'] : null,
                    'doc_type'       => 'purchase_invoice',
                    'doc_id'         => (int) $r['doc_id'],
                    'doc_no'         => (string) $r['doc_no'],
                    'issue_date'     => (string) $r['issue_date'],
                    'due_date'       => (string) $r['due_date'],
                    'status'         => (string) $r['status'],
                    'partner_id'     => (int) $r['partner_id'],
                    'partner_name'   => (string) $r['partner_name'],
                    'currency_code'  => (string) $r['currency_code'],
                    'booked_signed'  => $paid,
                    'foreign_signed' => 0.0,
                    'paid_ratio'     => $paid > 0.0 ? min(1.0, max(0.0, $settled / $paid)) : 0.0,
                ];
            },
            $limit,
        );
    }

    /**
     * Vydané faktury otevřené k asOf. Oproti původní verzi tři změny, VŠECHNY
     * výkonové (výstup je řádek po řádku shodný):
     *   1. `paid_as_of` a `advance_on_account` byly korelované poddotazy počítané
     *      na KAŽDÝ řádek sestavy (a `paid_as_of` v sobě mělo ještě jeden poddotaz
     *      na řádek platby); teď jsou to CTE agregace spočtené jednou.
     *   2. Filtr otevřenosti ({@see openFilterSql}) je v SQL, takže se do PHP
     *      netahají doklady, které sestava stejně zahodí.
     *   3. Volitelný `LIMIT` — sestava má strop, viz `SaldoService::MAX_OPEN_ITEMS`.
     *
     * Filtr je v `HAVING`, ne v obalujícím `SELECT * FROM (…)`: obal by MariaDB donutil
     * materializovat KAŽDÝ zaúčtovaný doklad do temp tabulky (nad `tmp_table_size` na disk),
     * což bylo měřitelně dvakrát dražší než ponechat agregaci v hlavním dotazu.
     *
     * `HAVING` NESMÍ odkazovat aliasy `paid_as_of` / `advance_on_account` /
     * `amount_to_pay` — jsou stejnojmenné jako podkladové sloupce a MariaDB tam
     * přednostně vezme SLOUPEC, tedy `NULL` místo `COALESCE(...,0)`, a filtr pak tiše
     * zahodí všechny řádky. Výrazy proto drží proměnné `$…Expr` a používají se v SELECT
     * i v HAVING doslova.
     *
     * ID (supplier/account/partner) jdou do SQL LITERÁLEM, ne placeholderem — jsou to
     * `int`, takže tudy neprojde uživatelský vstup (týž vzor jako
     * {@see PurchaseSettledExpr::settled()}). Není to kosmetika: nativní prepared
     * statement se v MariaDB optimalizuje BEZ znalosti hodnot a u těchhle ID pak
     * neprovede rozklad CTE na LATERAL DERIVED — naměřeno 1,63 s místo 0,81 s na
     * stejném dotazu. Zbylé placeholdery jsou tím pádem VŠECHNY `asOf`, takže se
     * parametry nedají prohodit.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchOpenInvoices(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?int $limit,
        ?int $partnerId = null,
        ?string $dueBefore = null,
        bool $orderByDue = false,
        bool $stream = false,
        ?array $invoiceIds = null,
        bool $currentRead = false,
        bool $componentsOnly = false,
    ): array|\Generator
    {
        $invoiceFilter = $invoiceIds === null ? '' : ' AND d.id IN (' . implode(',', array_map('intval', $invoiceIds)) . ')';
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $advanceCte = $this->advanceOnAccountCte('credit', $supplierId, $accountId, $currentRead);

        $paidExpr    = 'COALESCE(paid.paid_sum, 0)';
        $advanceExpr = 'ROUND(COALESCE(adv.advance_sum, 0), 2)';
        $toPayExpr   = 'COALESCE(d.amount_to_pay, 0)';
        $bookedExpr  = "ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 2)";
        $foreignExpr = "ROUND(SUM(CASE WHEN l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                                       THEN (CASE WHEN l.side = 'debit' THEN l.signed_amount_foreign ELSE -l.signed_amount_foreign END)
                                       ELSE 0 END), 2)";
        // Proplacený dobropis se v `invoice_payments` neobjeví (PAYABLE_TYPES ho tam
        // nepustí) — zkratka „vrácené peníze ⇒ poměr 1" je proto jediný způsob, jak
        // ho uzavřít. Zrcadlí zkratku `d.status='paid'` z fetchOpenPurchases().
        // SSOT predikátu: {@see CreditNoteRefundExpr}.
        $refundedExpr = CreditNoteRefundExpr::refundedAsOfSql('d');
        $manualExpr = 'ROUND(COALESCE(man.settled, 0), 2)';
        $ratio = self::withManualSettlementSql("CASE WHEN {$refundedExpr} THEN 1 ELSE " . self::paidRatioSql(
            "{$paidExpr} + {$advanceExpr}",
            "{$toPayExpr} + {$advanceExpr}",
        ) . ' END', $manualExpr, $bookedExpr);
        $manualCte = LinkedManualSettlementSql::sql(
            'invoice',
            'credit',
            LinkedManualSettlementSql::accountIdPredicate($accountId),
            (string) $supplierId,
            currentRead: $currentRead,
        );

        $sql =
            "WITH bank_settle AS (
                " . self::bankSettleCte($supplierId, $currentRead) . "
            ), man AS (
                {$manualCte}
            ), paid AS (
                SELECT ip.invoice_id, SUM(ip.amount) AS paid_sum
                  FROM invoice_payments ip
                  LEFT JOIN bank_transactions ipbt ON ipbt.id = ip.bank_transaction_id
                  LEFT JOIN bank_settle bs ON bs.bank_transaction_id = ip.bank_transaction_id
                 WHERE ip.supplier_id = {$supplierId}
                   AND COALESCE(bs.settled_on, DATE(ipbt.posted_at), ip.paid_on) <= ?
                 GROUP BY ip.invoice_id{$lock}
            ), advances AS (
                {$advanceCte}
            )
            SELECT d.id AS doc_id,
                   COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                   d.issue_date, d.due_date, d.status, d.invoice_type, d.paid_at, d.parent_invoice_id,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   {$toPayExpr} AS amount_to_pay,
                   {$paidExpr} AS paid_as_of,
                   {$advanceExpr} AS advance_on_account,
                   {$manualExpr} AS manual_settled,
                   {$bookedExpr} AS booked_signed,
                   {$foreignExpr} AS foreign_signed
              FROM journal_entries e
              JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              JOIN chart_of_accounts ca ON ca.id = l.account_id
              LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
              JOIN invoices d      ON d.id = e.source_id AND d.supplier_id = e.supplier_id
              JOIN clients cl      ON cl.id = d.client_id
              JOIN currencies cur  ON cur.id = d.currency_id
              LEFT JOIN paid       ON paid.invoice_id = d.id
              LEFT JOIN advances adv ON adv.advance_id = d.parent_invoice_id
              LEFT JOIN man        ON man.doc_id = d.id
             WHERE e.supplier_id = {$supplierId} AND e.source_type = 'invoice'
               AND e.posted_at IS NOT NULL
               AND e.entry_date <= ?
               AND (e.reversed_by IS NULL OR rev.entry_date > ?)
               AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
               AND d.status <> 'draft'
               AND (d.status <> 'cancelled' OR d.cancelled_at IS NULL OR DATE(d.cancelled_at) > ?)
               " . self::partnerSql($partnerId) . self::dueBeforeSql('d', $dueBefore) . $invoiceFilter . "
             GROUP BY d.id, doc_no, d.issue_date, d.due_date, d.status, d.invoice_type, d.paid_at, d.parent_invoice_id,
                      cl.id, cl.company_name, cur.code, d.amount_to_pay,
                      paid.paid_sum, adv.advance_sum, man.settled
            HAVING " . ($componentsOnly ? '1=1' : self::openFilterSql($bookedExpr, $ratio)) . "
             " . ($stream ? ' ORDER BY cl.id, d.due_date, d.id' : self::orderSql('d', $orderByDue));

        $fetch = $stream ? 'iterateDefinitiveOpenRows' : 'fetchDefinitiveOpenRows';
        return $this->$fetch(
            $sql,
            static fn (string $pageSql): array => self::asOfParams($pageSql, $asOf),
            function (array $r) use ($asOf, $componentsOnly): array {
                if ($componentsOnly) return $r;
                // Předplacení z proformy uhrazené PŘÍMO na tenhle účet (viz advanceOnAccountCte):
                // vstupuje do čitatele i jmenovatele poměru. `amount_to_pay` finální faktury je
                // o zálohu snížené (u plně předplacené je nulové), takže bez téhle korekce vyjde
                // poměr 0 (nebo 1 z nesouvisejícího zbytku) a doklad svítí jako celý otevřený.
                $advance = round((float) $r['advance_on_account'], 2);
                return [
                    'doc_type'       => 'invoice',
                    'doc_id'         => (int) $r['doc_id'],
                    'doc_no'         => (string) $r['doc_no'],
                    'issue_date'     => (string) $r['issue_date'],
                    'due_date'       => (string) $r['due_date'],
                    'status'         => (string) $r['status'],
                    'partner_id'     => (int) $r['partner_id'],
                    'partner_name'   => (string) $r['partner_name'],
                    'currency_code'  => (string) $r['currency_code'],
                    'booked_signed'  => round((float) $r['booked_signed'], 2),
                    'foreign_signed' => round((float) $r['foreign_signed'], 2),
                    // Stejnoměnný poměr k asOf; invoice_payments pokrývá bankovní,
                    // hotovostní i ruční platby jednotně. Dobropis tam ale nikdy
                    // nepřistane — jeho proplacení nese stav dokladu (CreditNoteRefundExpr).
                    'paid_ratio'     => self::withManualSettlement(
                        $this->paidRatio(
                            CreditNoteRefundExpr::isRefundedAsOf(
                                (string) $r['invoice_type'],
                                (string) $r['status'],
                                $r['paid_at'] === null ? null : (string) $r['paid_at'],
                                $asOf,
                                (float) $r['amount_to_pay'],
                                $r['parent_invoice_id'] === null ? null : (int) $r['parent_invoice_id'],
                            ),
                            (float) $r['paid_as_of'] + $advance,
                            (float) $r['amount_to_pay'] + $advance,
                        ),
                        (float) $r['manual_settled'],
                        round((float) $r['booked_signed'], 2),
                    ),
                ];
            },
            $limit,
            ...($stream ? [$currentRead, $componentsOnly] : []),
        );
    }

    /**
     * Poměr uhrazení rozšířený o ruční zápis navázaný na doklad ({@see LinkedManualSettlementSql}).
     * Ruční vyrovnání (kurzový rozdíl, odpis) žije jen v CZK na saldokontním účtu, takže
     * se k poměru z úhrad přičítá jako podíl zaúčtované částky, ne jako úhrada v měně
     * dokladu. `$manual` i `$bookedOriented` jsou orientované na normální stranu dokladu
     * (kladné = snižuje saldo, resp. předpis), takže dobropis vychází se správným znaménkem.
     */
    private static function withManualSettlement(float $ratio, float $manual, float $bookedOriented): float
    {
        if (abs($manual) < 0.005 || abs($bookedOriented) < 0.005) {
            return $ratio;
        }
        return max(0.0, min(1.0, $ratio + $manual / $bookedOriented));
    }

    /** SQL protějšek {@see withManualSettlement}; výrazy se vkládají doslova (HAVING). */
    private static function withManualSettlementSql(string $ratio, string $manual, string $bookedOriented): string
    {
        return "CASE WHEN ABS({$manual}) < 0.005 OR ABS({$bookedOriented}) < 0.005 THEN ({$ratio})
                     ELSE LEAST(1, GREATEST(0, ({$ratio}) + {$manual} / ({$bookedOriented}))) END";
    }

    /**
     * Korelovaný poddotaz: kolik z dokladu `d` je předplaceno ZÁLOHOU (proformou /
     * zálohovou PF), jejíž úhrada je zaúčtovaná PŘÍMO NA TENHLE saldokontní účet —
     * tedy bez průchodu přes 324/314.
     *
     * Proč to musí být: proforma je samostatný doklad (`invoices.invoice_type='proforma'`,
     * resp. `purchase_invoices.document_kind='advance'`) a finální faktura na ni ukazuje
     * přes `parent_invoice_id` / `advance_purchase_invoice_id`. `invoice_payments.invoice_id`
     * i `payment_matches.purchase_invoice_id` míří na ZÁLOHU, ne na finální doklad. Účetní,
     * která inkaso proformy účtuje rovnou na 311 (a ne na 324), tak dostane finální fakturu
     * se `amount_to_pay = 0`, žádnou vlastní platbou a plným předpisem na 311 → doklad
     * svítí jako celý otevřený, přestože hlavní kniha ho má vyrovnaný.
     *
     * Podmínka „úhrada zálohy je zaúčtovaná na TENHLE účet" je zároveň rozlišovací znak
     * proti tenantům, kteří 324/314 POUŽÍVAJÍ: tam inkaso kredituje 324 (ne 311) a finální
     * faktura si zálohu zúčtuje sama zápisem 324 MD / 311 D ve VLASTNÍM zápisu, takže
     * `booked_signed` už přichází netto. Pro ně vyjde tenhle poddotaz 0 a chování se nemění.
     *
     * Dřív to byl KORELOVANÝ poddotaz počítaný na každý řádek sestavy; teď je to CTE
     * agregované jednou přes všechny zálohy firmy a napojené `LEFT JOIN`em na
     * `parent_invoice_id` / `advance_purchase_invoice_id`. Podmínka „záloha má vůbec
     * nějaký navazující doklad" ({@see $childExists}) není nový filtr, jen předvýběr:
     * zálohy bez následníka se stejně nemají na co napojit, takže výsledek je shodný.
     *
     * Obsahuje ČTYŘI placeholdery, všechny asOf (entry_date a storno za každou větev);
     * ID jsou literály.
     *
     * @param 'credit'|'debit' $side strana, na kterou úhrada zálohy dopadá na daném účtu
     *                               (311 pohledávka → credit, 321 závazek → debit)
     */
    private function advanceOnAccountCte(string $side, int $supplierId, int $accountId, bool $currentRead = false): string
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        if ($side === 'credit') {
            $key = 'pip.invoice_id';
            $from = "FROM invoice_payments pip
                     JOIN invoices pf ON pf.id = pip.invoice_id AND pf.supplier_id = pip.supplier_id
                                     AND pf.invoice_type = 'proforma'";
            $childExists = "EXISTS (SELECT 1 FROM invoices ch
                                     WHERE ch.supplier_id = pf.supplier_id AND ch.parent_invoice_id = pf.id{$lock})";
            $cashLink = 'cd.invoice_payment_id = pip.id';
        } else {
            $key = 'pip.purchase_invoice_id';
            $from = "FROM payment_matches pip
                     JOIN purchase_invoices pf ON pf.id = pip.purchase_invoice_id AND pf.supplier_id = pip.supplier_id
                                              AND pf.document_kind = 'advance'";
            $childExists = "EXISTS (SELECT 1 FROM purchase_invoices ch
                                     WHERE ch.supplier_id = pf.supplier_id AND ch.advance_purchase_invoice_id = pf.id{$lock})";
            $cashLink = 'cd.purchase_invoice_id = pf.id';
        }

        // Bankovní a pokladní větev jsou dva samostatné EXISTS, ne jeden s OR uvnitř.
        // Jediný EXISTS MariaDB přepsala na semi-join a vedla ho od CELÉHO deníku firmy
        // přes všechny zálohy do plateb (ZENERGO: 3,7 mil. bodových čtení, 3,5 s). Takhle
        // je každá větev bodový dotaz přes idx_je_supplier_source na jednu platbu.
        $postedOnAccount = "JOIN journal_entry_lines pl ON pl.entry_id = pe.id AND pl.supplier_id = pe.supplier_id
                      JOIN chart_of_accounts pca ON pca.id = pl.account_id
                      LEFT JOIN journal_entries prev ON prev.id = pe.reversed_by
                     WHERE pe.posted_at IS NOT NULL AND pe.entry_date <= ?
                       AND (pe.reversed_by IS NULL OR prev.entry_date > ?)
                       AND pl.side = '{$side}'
                       AND (pca.id = {$accountId} OR pca.parent_id = {$accountId})";

        return
            "SELECT {$key} AS advance_id, SUM(pip.amount) AS advance_sum
               {$from}
              WHERE pip.supplier_id = {$supplierId}
                AND {$childExists}
                AND (
                    (pip.bank_transaction_id IS NOT NULL AND EXISTS (
                        SELECT 1
                          FROM journal_entries pe
                          {$postedOnAccount}
                           AND pe.supplier_id = pip.supplier_id
                           AND pe.source_type = 'bank' AND pe.source_id = pip.bank_transaction_id{$lock}
                    ))
                    OR EXISTS (
                        SELECT 1
                          FROM cash_documents cd
                          JOIN journal_entries pe ON pe.supplier_id = cd.supplier_id
                                                 AND pe.source_type = 'cash' AND pe.source_id = cd.id
                          {$postedOnAccount}
                           AND cd.supplier_id = pip.supplier_id AND {$cashLink}{$lock}
                    )
                )
              GROUP BY {$key}{$lock}";
    }

    /**
     * Přijaté faktury otevřené k asOf — zrcadlo {@see fetchOpenInvoices} (viz tamní
     * komentář k CTE, filtru otevřenosti a limitu).
     *
     * `settled_by_offsets` zůstává KORELOVANÝM výrazem záměrně: je to sdílený zdroj
     * pravdy {@see PurchaseSettledExpr::offsetSettledAsOf()}, který používají i uzávěrka
     * a zápočty. Vlastní „rychlejší" kopie by z něj udělala dvě pravdy — a zápočtové
     * tabulky (`offset_agreement_items`, `invoice_settlements`) jsou o řády menší než
     * platby, takže na nich stojí zlomek nákladu.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchOpenPurchases(
        int $supplierId,
        int $accountId,
        string $asOf,
        ?int $limit,
        ?int $partnerId = null,
        ?string $dueBefore = null,
        bool $orderByDue = false,
        bool $currentRead = false,
        bool $componentsOnly = false,
        ?int $documentId = null,
    ): array
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $documentFilter = $documentId === null ? '' : ' AND d.id = ' . $documentId;
        $advanceCte = $this->advanceOnAccountCte('debit', $supplierId, $accountId, $currentRead);
        $offsets = PurchaseSettledExpr::offsetSettledAsOf('d', $currentRead);

        $settlementDate = 'COALESCE(bs.settled_on, DATE(bt.posted_at))';
        $matchAmount    = PurchaseSettledExpr::bankAmountSql('pm', 'bt', 'mbs', 'mpi', 'mdc');
        $cashAmount     = PurchaseSettledExpr::cashAmountSql('cd', 'cpi', 'cdc');
        $cashExpr       = 'COALESCE(ch.cash_sum, 0)';
        $matchedExpr    = 'COALESCE(m.matched_sum, 0)';
        $afterExpr      = 'COALESCE(m.matched_after, 0)';
        $advanceExpr    = 'ROUND(COALESCE(adv.advance_sum, 0), 2)';
        $toPayExpr      = 'COALESCE(d.amount_to_pay, 0)';
        $bookedExpr     = "ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 2)";
        $foreignExpr    = "ROUND(SUM(CASE WHEN l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                                          THEN (CASE WHEN l.side = 'debit' THEN l.signed_amount_foreign ELSE -l.signed_amount_foreign END)
                                          ELSE 0 END), 2)";

        // Zkratka „doklad je k asOf plně uhrazený podle svého stavu" — SQL protějšek
        // $paidByStatusAsOf níže; bez ní by se do PHP tahaly i doklady, které sestava
        // zahodí jako uzavřené. `{$offsets}` se v HAVING vyhodnotí znovu (aliasy tu
        // odkazovat nelze, viz fetchOpenInvoices) — jsou to dva indexové poddotazy
        // nad drobnými zápočtovými tabulkami.
        // Zkratka „paid ⇒ plně kryto" NEPLATÍ pro doklad, jehož evidované úhrady ho
        // prokazatelně nepokrývají: úhrada existuje, ale nechává na saldokontě zbytek nad
        // korunovou toleranci. Přesně tak vypadal ručně spárovaný nedoplatek — doklad
        // `paid`, banka zaúčtovala jen skutečnou platbu a zbytek na 321 saldokonto
        // schovalo. Dobropisy (záporné `amount_to_pay`) se nehodnotí: jejich proplacení
        // se do poměru nepromítá a zkratka je jediné, co je uzavírá. SQL práh je o haléř
        // nižší než PHP ({@see SHORTFALL_TOLERANCE_CZK}), aby filtr zůstal nadmnožinou.
        $ratioFor = static function (string $offsets) use ($matchedExpr, $cashExpr, $advanceExpr, $toPayExpr, $afterExpr, $bookedExpr): string {
            $signal = "{$matchedExpr} + {$cashExpr} + ({$offsets}) + {$advanceExpr}";
            $signalRatio = self::paidRatioSql($signal, "{$toPayExpr} + {$advanceExpr}");
            $shortfallThreshold = number_format(self::SHORTFALL_TOLERANCE_CZK - 0.01, 2, '.', '');

            return "CASE WHEN d.status = 'paid' AND d.paid_at IS NOT NULL
                          AND DATE(d.paid_at) <= ? AND {$afterExpr} = 0
                          AND NOT ({$toPayExpr} > 0 AND ({$signal}) > 0.005
                                   AND ABS({$bookedExpr} * (1 - ({$signalRatio}))) > {$shortfallThreshold})
                     THEN 1 ELSE {$signalRatio} END";
        };
        $manualExpr = 'ROUND(COALESCE(man.settled, 0), 2)';
        $manualCte = LinkedManualSettlementSql::sql(
            'purchase_invoice',
            'debit',
            LinkedManualSettlementSql::accountIdPredicate($accountId),
            (string) $supplierId,
            currentRead: $currentRead,
        );

        $sql =
            "WITH bank_settle AS (
                " . self::bankSettleCte($supplierId, $currentRead) . "
            ), matches AS (
                SELECT pm.purchase_invoice_id AS doc_id,
                       SUM(CASE WHEN {$settlementDate} <= ? THEN {$matchAmount} ELSE 0 END) AS matched_sum,
                       SUM(CASE WHEN {$settlementDate} >  ? THEN 1 ELSE 0 END) AS matched_after
                  FROM payment_matches pm
                  JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                  JOIN bank_statements mbs ON mbs.id = bt.statement_id
                  JOIN purchase_invoices mpi ON mpi.id = pm.purchase_invoice_id AND mpi.supplier_id = pm.supplier_id
                  JOIN currencies mdc ON mdc.id = mpi.currency_id
                  LEFT JOIN bank_settle bs ON bs.bank_transaction_id = pm.bank_transaction_id
                 WHERE pm.supplier_id = {$supplierId} AND pm.purchase_invoice_id IS NOT NULL
                 GROUP BY pm.purchase_invoice_id{$lock}
            ), cash AS (
                -- Pokladní úhrady k asOf (vratka odečítá). Stornovaný doklad se počítá,
                -- dokud jeho protizápis k asOf ještě nenastal — stejně jako u zápočtů.
                SELECT cd.purchase_invoice_id AS doc_id, SUM({$cashAmount}) AS cash_sum
                  FROM cash_documents cd
                  JOIN purchase_invoices cpi ON cpi.id = cd.purchase_invoice_id AND cpi.supplier_id = cd.supplier_id
                  JOIN currencies cdc ON cdc.id = cpi.currency_id
                  LEFT JOIN journal_entries crev ON crev.id = cd.reversal_entry_id
                 WHERE cd.supplier_id = {$supplierId} AND cd.purchase_invoice_id IS NOT NULL
                   AND cd.issue_date <= ?
                   AND (cd.status = 'posted' OR (cd.status = 'reversed' AND crev.entry_date > ?))
                 GROUP BY cd.purchase_invoice_id{$lock}
            ), advances AS (
                {$advanceCte}
            ), man AS (
                {$manualCte}
            )
            SELECT d.id AS doc_id,
                   COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                   d.issue_date, d.due_date, d.status, d.paid_at,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   {$toPayExpr} AS amount_to_pay,
                   {$matchedExpr} AS paid_from_matches,
                   {$cashExpr} AS paid_from_cash,
                   {$afterExpr} AS matches_after_as_of,
                   {$offsets} AS settled_by_offsets,
                   {$advanceExpr} AS advance_on_account,
                   {$manualExpr} AS manual_settled,
                   {$bookedExpr} AS booked_signed,
                   {$foreignExpr} AS foreign_signed
              FROM journal_entries e
              JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              JOIN chart_of_accounts ca ON ca.id = l.account_id
              LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
              JOIN purchase_invoices d ON d.id = e.source_id AND d.supplier_id = e.supplier_id
              JOIN clients cl          ON cl.id = d.vendor_id
              JOIN currencies cur      ON cur.id = d.currency_id
              LEFT JOIN matches m      ON m.doc_id = d.id
              LEFT JOIN cash ch        ON ch.doc_id = d.id
              LEFT JOIN advances adv   ON adv.advance_id = d.advance_purchase_invoice_id
              LEFT JOIN man            ON man.doc_id = d.id
             WHERE e.supplier_id = {$supplierId} AND e.source_type = 'purchase_invoice'
               AND e.posted_at IS NOT NULL
               AND e.entry_date <= ?
               AND (e.reversed_by IS NULL OR rev.entry_date > ?)
               AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
               AND d.status <> 'draft'
               AND (d.status <> 'cancelled' OR d.cancelled_at IS NULL OR DATE(d.cancelled_at) > ?)
               " . self::partnerSql($partnerId) . self::dueBeforeSql('d', $dueBefore) . $documentFilter . "
             GROUP BY d.id, d.supplier_id, doc_no, d.issue_date, d.due_date, d.status, d.paid_at,
                      cl.id, cl.company_name, cur.code, d.amount_to_pay,
                      m.matched_sum, m.matched_after, ch.cash_sum, adv.advance_sum, man.settled
            HAVING " . ($componentsOnly ? '1=1' : self::openFilterSql($bookedExpr, self::withManualSettlementSql(
                $ratioFor(PurchaseSettledExpr::offsetSettledAsOf('d', $currentRead)),
                $manualExpr,
                "-({$bookedExpr})",
            ))) . "
             " . self::orderSql('d', $orderByDue);

        return iterator_to_array($this->iterateDefinitiveOpenRows(
            $sql,
            static fn (string $pageSql): array => self::asOfParams($pageSql, $asOf),
            function (array $r) use ($asOf, $componentsOnly): array {
                // `status='paid'` je stav DOKLADU (kdy ho účetní odkliká), ne datum, ke kterému
                // úhradu zná HLAVNÍ KNIHA. Když k dokladu existuje bankovní párování, které deník
                // uznává AŽ PO rozvahovém dni (PF vystavená 31. 12., zaplacená v lednu, ale
                // `paid_at` doklad nese v prosinci), zkratka „paid ⇒ ratio 1" doklad ze salda
                // vyhodí, přestože HK ho k asOf má otevřený — a konfrontace pak nesedí o celou
                // fakturu. V takovém případě zkratku potlačíme a poměr počítáme z DATOVANÝCH
                // úhrad. Dokladům BEZ bankovního párování (hotovost, ruční „označit zaplaceno")
                // zkratka zůstává — jinak by se z nich staly trvale otevřené položky.
                $paidByStatusAsOf = (string) $r['status'] === 'paid'
                    && $r['paid_at'] !== null
                    && substr((string) $r['paid_at'], 0, 10) <= $asOf
                    && (int) $r['matches_after_as_of'] === 0;
                $advance = round((float) $r['advance_on_account'], 2);
                $booked = round((float) $r['booked_signed'], 2);
                $signal = (float) $r['paid_from_matches'] + (float) $r['paid_from_cash']
                    + (float) $r['settled_by_offsets'] + $advance;
                $toPay = (float) $r['amount_to_pay'] + $advance;
                // Nedoplatek na „uhrazeném" dokladu (viz $ratioFor výš): úhrady existují,
                // ale nechávají na saldokontě zbytek nad toleranci → zkratka neplatí.
                $shortfall = (float) $r['amount_to_pay'] > 0
                    && $signal > 0.005
                    && abs(self::settlementAmounts($booked, $this->paidRatio(false, $signal, $toPay))['remaining'])
                        > self::SHORTFALL_TOLERANCE_CZK;
                if ($componentsOnly) return [...$r, 'fully_paid_as_of' => $paidByStatusAsOf && !$shortfall];
                return [
                    'doc_type'       => 'purchase_invoice',
                    'doc_id'         => (int) $r['doc_id'],
                    'doc_no'         => (string) $r['doc_no'],
                    'issue_date'     => (string) $r['issue_date'],
                    'due_date'       => (string) $r['due_date'],
                    'status'         => (string) $r['status'],
                    'partner_id'     => (int) $r['partner_id'],
                    'partner_name'   => (string) $r['partner_name'],
                    'currency_code'  => (string) $r['currency_code'],
                    'booked_signed'  => round((float) $r['booked_signed'], 2),
                    'foreign_signed' => round((float) $r['foreign_signed'], 2),
                    // status='paid' je autoritativní až od paid_at (a jen bez nedoplatku); jinak
                    // se poměr skládá z kanálů, kterými se přijatá faktura umí vyrovnat: banka
                    // (payment_matches převedené do měny dokladu), pokladna a oba zápočty
                    // ({@see PurchaseSettledExpr::offsetSettledAsOf}). Zálohová PF uhrazená přímo
                    // na 321 (bez 314) vstupuje do poměru zrcadlově k vydané větvi.
                    'paid_ratio'     => self::withManualSettlement(
                        $this->paidRatio($paidByStatusAsOf && !$shortfall, $signal, $toPay),
                        (float) $r['manual_settled'],
                        -$booked,
                    ),
                ];
            },
            $limit,
            $currentRead,
            $componentsOnly,
        ), false);
    }

    /**
     * Poměr uhrazeno/celkem (0..1). Autoritativní stav plné úhrady má přednost (kryje i
     * zaokrouhlovací toleranci InvoicePaymentService a hotovostní plnou úhradu PF,
     * která `paidSignal` vůbec nenaplní). `amount_to_pay` může být záporné
     * (dobropis) — poměr pak vyjde 0 (dobropis se neplatí, PAYABLE_TYPES ho
     * vylučuje z invoice_payments), doklad zůstane plně "otevřený" v původním
     * (záporném) znaménku, což je žádoucí pro netto součet v partnerově saldu.
     */
    private function paidRatio(bool $fullyPaidAsOf, float $paidSignal, float $amountToPay): float
    {
        if ($fullyPaidAsOf) {
            return 1.0;
        }
        if (abs($amountToPay) < 0.005) {
            return 0.0;
        }
        $ratio = $paidSignal / $amountToPay;
        return max(0.0, min(1.0, $ratio));
    }
}
