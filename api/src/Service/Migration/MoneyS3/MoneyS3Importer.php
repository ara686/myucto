<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\RecurringSeriesTakeover;
use PDO;

/**
 * Převod agendy Money S3 do firmy v MyÚčtu — orchestrátor kroků průvodce.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * faktury → pokladna a banka → vazby dokladů na deník a úhrady → pozastavené šablony
 * pravidelných faktur z historie → uzávěrka historických
 * let → mzdy (návrh kontací, kontrolní úhrny, zapnutí modulu) → rekonciliace. Automatika účtování je po celou dobu vypnutá
 * ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí —
 * protokol ukáže přesně to, co by udělal ostrý převod, a v databázi nic nezůstane.
 *
 * **Ostrý převod** zapisuje po krocích a každý krok je idempotentní
 * ({@see MoneyS3ImportRepository}): opakovaný běh téže zálohy nic nezdvojí a běh
 * přerušený chybou pokračuje tam, kde skončil.
 */
final class MoneyS3Importer
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat — další na nich stojí. */
    private const CRITICAL_STEPS = [ChartImporter::STEP, JournalImporter::STEP, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartImporter $chart,
        private readonly JournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly CodebookImporter $codebooks,
        private readonly InvoiceImporter $invoices,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly DimensionImporter $dimensions,
        private readonly AssetImporter $assets,
        private readonly VatCoefficientSeeder $coefficients,
        private readonly HistoricalYearCloser $closer,
        private readonly MoneyS3Reconciler $reconciler,
        private readonly TableStatistics $statistics,
        private readonly PayrollImporter $payroll,
        private readonly RecurringSeriesTakeover $recurring,
    ) {}

    /** @return list<string> klíče kroků v pořadí, v jakém běží */
    public static function stepKeys(): array
    {
        return [
            ChartImporter::STEP,
            JournalImporter::STEP,
            self::STEP_ACCOUNTING_MODE,
            CodebookImporter::STEP_PARTNERS,
            CodebookImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DimensionImporter::STEP,
            DocumentLinker::STEP_PAYMENTS,
            RecurringSeriesTakeover::STEP,
            AssetImporter::STEP,
            AssetImporter::STEP_SMALL,
            VatCoefficientSeeder::STEP,
            HistoricalYearCloser::STEP,
            PayrollImporter::STEP,
            MoneyS3Reconciler::STEP,
        ];
    }

    /**
     * Rozvrh období a nenavazující roky zálohy při výchozích volbách převodu - to, co
     * z deníku potřebuje kontrola před převodem v náhledu průvodce. Počítá ho job nahrání
     * do `meta.json`; náhled pak deníky (u velké agendy gigabajty) znovu nečte.
     *
     * Rozvrh se počítá v kalendáři účetního roku firmy (`$supplierId`, bez něj kalendářní
     * rok) a nese jeho počáteční měsíc: změní-li firma nastavení po nahrání, kontrola před
     * převodem náhled zahodí a deník přečte znovu.
     *
     * @return array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>,start_month:int}
     */
    public function journalPreview(Ms3Backup $backup, ?int $supplierId = null): array
    {
        $options = new ImportOptions();
        $calendar = $supplierId !== null ? $this->periods->fiscalCalendar($supplierId) : FiscalCalendar::calendar();
        return [
            'plan' => $this->journal->plan($backup, $options, $calendar),
            'chain_breaks' => $this->journal->chainBreaks($backup, $options, $calendar),
            'start_month' => $calendar->startMonth(),
        ];
    }

    /**
     * Náhled deníku z `meta.json` platí pro kalendář účetního roku firmy - po změně nastavení
     * „Účetní rok začíná" se musí přečíst znovu.
     */
    public function journalPreviewCurrent(mixed $preview, int $supplierId): bool
    {
        return self::journalPreviewComplete($preview) && $preview['start_month'] === $this->periods->fiscalCalendar($supplierId)->startMonth();
    }

    /**
     * Náhled agendy pro průvodce v účetních letech FIRMY: náhled nahrání počítá rok adresáře
     * ROK v kalendářním roce, převod ale adresář převádí jako účetní období firmy podle
     * rozvrhu deníku ({@see JournalImporter::plan()}). U firmy s hospodářským rokem proto rok
     * (`fiscal_year`) nahradí label období firmy a přidá jeho hranice (`period_start`,
     * `period_end`) - podle stejného roku se pak páruje i nahraná předvaha z Money a volba
     * „Převést od roku". Kalendářní firma dostane náhled beze změny.
     *
     * @param array<string,mixed> $agenda {@see AgendaInfo::toArray()}
     * @param array{plan:list<array<string,mixed>>,start_month:int} $journalPreview náhled deníku v kalendáři firmy ({@see journalPreview()})
     * @return array<string,mixed>
     */
    public static function companyAgenda(array $agenda, array $journalPreview): array
    {
        if ($journalPreview['start_month'] === 1) {
            return $agenda;
        }
        $plan = array_column($journalPreview['plan'], null, 'dir');
        foreach ((array) ($agenda['years'] ?? []) as $i => $year) {
            $item = $plan[(string) ($year['dir'] ?? '')] ?? null;
            if ($item === null) {
                continue;
            }
            $agenda['years'][$i]['fiscal_year'] = (int) $item['year'];
            $agenda['years'][$i]['period_start'] = (string) $item['starts_on'];
            $agenda['years'][$i]['period_end'] = (string) $item['ends_on'];
        }
        return $agenda;
    }

    /** Náhled deníku z `meta.json` je úplný (nahrání před jeho zavedením ho nemá). */
    public static function journalPreviewComplete(mixed $preview): bool
    {
        return is_array($preview) && is_array($preview['plan'] ?? null) && is_array($preview['chain_breaks'] ?? null)
            && is_int($preview['start_month'] ?? null)
            && array_all($preview['plan'], static fn (mixed $item): bool => is_array($item) && is_array($item['source_dates'] ?? null));
    }

    /**
     * Kontrola před převodem — nic nezapisuje. Chyba převod zastaví, upozornění ne.
     *
     * `$journalPreview` = rozvrh a nenavazující roky z `meta.json` ({@see journalPreview()});
     * platí jen pro výchozí volby převodu, s jinými se deník čte ze zálohy.
     *
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options, ?array $journalPreview = null): array
    {
        $calendar = $this->periods->fiscalCalendar($supplierId);
        if ($options->fromYear !== null || $options->firstPeriodStart !== null || !self::journalPreviewComplete($journalPreview)
            || $journalPreview['start_month'] !== $calendar->startMonth()) {
            $journalPreview = null;
        }
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };

        $stmt = $this->db->pdo()->prepare('SELECT id, ic, company_name, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }

        $supplierIco = CodebookImporter::ico((string) ($supplier['ic'] ?? ''));
        $agendaIco = CodebookImporter::ico($agenda->ico);
        if ($agendaIco === '') {
            $add('warning', 'agenda_ico_missing', 'Záloha neobsahuje IČO firmy — ověřte, že jde o agendu této firmy.');
        } elseif ($supplierIco === '') {
            $add('warning', 'supplier_ico_missing', "Firma v MyÚčtu nemá vyplněné IČO, záloha je agenda IČO {$agendaIco}.");
        } elseif ($supplierIco !== $agendaIco) {
            // Záloha cizí firmy by se jinak vmíchala do účetnictví téhle — tenantová
            // izolace na úrovni obsahu, ne jen oprávnění.
            $add('error', 'ico_mismatch', "Záloha je agenda IČO {$agendaIco}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $agendaIco, 'supplier' => $supplierIco]);
        }
        if (($agendaIco === '' || $supplierIco === '') && !$options->isDryRun() && !$options->confirmedIco) {
            // Bez IČO na jedné ze stran nejde ověřit, že agenda patří téhle firmě. Ostrý
            // převod by cizí účetnictví vmíchal natrvalo — spustí se jen s potvrzením.
            $add('error', 'ico_unverified', 'Nelze ověřit, že záloha patří této firmě (chybí IČO). Ostrý převod vyžaduje výslovné potvrzení.');
        }

        foreach ($agenda->warnings as $w) {
            $add('warning', $w['code'], $w['message']);
        }

        $plan = $journalPreview['plan'] ?? $this->journal->plan($backup, $options, $calendar);
        if ($plan !== []) {
            try { (new AccountingFrameworkImportGuard($this->db))->assertCz500Periods($supplierId, $plan); }
            catch (AccountingFrameworkException $e) { $add('error', $e->errorCode, $e->getMessage()); }
        }
        if ($plan === [] && $agenda->taxRecordsOnly()) {
            $add('error', 'tax_records_not_supported', AgendaInfo::TEXT_TAX_RECORDS_NOT_SUPPORTED);
        } elseif ($plan === [] && $options->fromYear !== null) {
            $add('error', 'from_year_empty', "Od roku {$options->fromYear} záloha nemá žádný účetní rok — není co převést.", ['from_year' => $options->fromYear]);
        } elseif ($plan === []) {
            $add('error', 'no_journal', 'Záloha neobsahuje účetní deník — není co převést.');
        }
        // meta.json ukládá celé částky bez desetinné části jako celá čísla.
        $breaks = $journalPreview !== null
            ? array_map(static fn (array $b): array => ['accounts' => array_map('floatval', (array) $b['accounts'])] + $b, $journalPreview['chain_breaks'])
            : $this->journal->chainBreaks($backup, $options, $calendar);
        foreach ($breaks as $b) {
            $code = (string) array_key_first($b['accounts']);
            $add('warning', 'opening_chain_break', sprintf(
                $b['next_has_opening']
                    ? 'Konečné stavy roku %d nesedí v Money na počáteční stavy roku %d (%d účtů, např. %s: %s Kč). Rok %1$d a všechny pozdější proto nepůjde uzavřít.'
                    : 'Rok %2$d nemá v Money počáteční stavy, konečné stavy roku %1$d na něj nenavazují (%3$d účtů, např. %4$s: %5$s Kč). Rok %1$d a všechny pozdější proto nepůjde uzavřít.',
                $b['year'], $b['next'], count($b['accounts']), $code, number_format($b['accounts'][$code], 2, ',', ' ')
            ), ['year' => $b['year'], 'next' => $b['next'], 'accounts' => array_slice($b['accounts'], 0, 20, true)]);
        }
        $suggested = JournalImporter::suggestedFromYear($breaks);
        if ($suggested !== null) {
            $add('info', 'suggested_from_year', "Roky od {$suggested} v Money navazují — převod od roku {$suggested} (volba „Převést od roku“) půjde celý uzavřít. Starší roky zůstanou v archivu Money.", ['from_year' => $suggested]);
        }
        foreach ($plan as $item) {
            // Adresář ROK = jeden účetní rok Money = jedno účetní období firmy. Tvar roku
            // určuje nastavení firmy „Účetní rok začíná"; rok zdroje, který by se rozpadl do
            // dvou období, převod zastaví s návrhem správného měsíce.
            try {
                MigrationPeriods::assertSourceYear($calendar, (array) $item['source_dates'], "{$item['year']} (adresář {$item['dir']})");
            } catch (FiscalYearMismatchException $e) {
                $add('error', 'fiscal_year_mismatch', $e->getMessage(), ['year' => $item['year'], 'suggested_start_month' => $e->suggestedStartMonth]);
            }
            $period = $this->periods->findByYear($supplierId, $item['year']);
            if ($period === null) {
                continue;
            }
            $mapped = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $item['year']) !== null;
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$item['year']} už obsahuje {$foreign} zápisů, které nevznikly převodem z Money. Deník z Money se do rozjetého účetnictví nepřimíchává.", ['year' => $item['year'], 'entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$item['year']} je v MyÚčtu uzavřené.", ['year' => $item['year']]);
            }
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        return $out;
    }

    /**
     * `$agenda` a `$journalPreview` = náhled agendy a deníku, který už spočítal job nahrání
     * (`meta.json`); bez nich se čtou ze zálohy.
     *
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     */
    public function run(
        int $supplierId,
        int $userId,
        Ms3Backup $backup,
        ImportOptions $options,
        ?int $runId = null,
        ?callable $progress = null,
        ?callable $shouldCancel = null,
        ?AgendaInfo $agenda = null,
        ?array $journalPreview = null,
    ): ImportProtocol {
        $protocol = new ImportProtocol($options->mode, $options->acceptDifferences);
        $agenda ??= AgendaInfo::fromBackup($backup);
        $protocol->set('agenda', $agenda->toArray());
        $protocol->set('options', $options->toArray());

        $preflight = $this->preflight($supplierId, $backup, $agenda, $options, $journalPreview);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $ctx = new ImportContext($supplierId, $userId, $backup, $agenda, $options, $protocol, $this->periods->fiscalCalendar($supplierId));
        $ctx->runId = $runId;
        $ctx->progress = $progress;

        $frameworkGuard = new AccountingFrameworkImportGuard($this->db);
        $frameworkPeriods = $this->journal->plan($backup, $options, $this->periods->fiscalCalendar($supplierId));
        $pdo = $this->db->pdo();
        $dryRun = $options->isDryRun();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes
        // savepoint — vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT money_s3_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            // Stav před PRVNÍM ostrým během, po kterém se automatika neobnovila (neúspěšný
            // nebo spadlý běh ji nechal vypnutou) — jinak by se „obnovilo" vypnuto. K běhu
            // se ukládá PŘED vypnutím: kdyby spadl i tenhle běh, další snímek najde.
            $snapshot = ($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId);
            // Zkouška nanečisto vypnutí automatiky jen ohlásí. Zápis do řádku firmy by v její
            // jediné transakci držel zámek až do konce a každý nový doklad firmy (cizí klíč
            // na firmu) by na něj čekal; převod sám automatiku ke své práci nepotřebuje.
            if (!$dryRun) {
                $frameworkGuard->writeCz500Periods($supplierId, $frameworkPeriods, function () use ($runId, $supplierId, $snapshot, $userId): void {
                    if ($runId !== null) $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
                    $this->unit->disableAutomation($supplierId, $userId > 0 ? $userId : null);
                });
            } else {
                $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];
            $protocol->set('automation', $automation);

            $steps = $this->steps($ctx);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                if (!$dryRun && $key === MoneyS3Reconciler::STEP) {
                    $this->statistics->refreshAfterImport(['money_s3_import_map']);
                }
                try {
                    // Uzávěrka si transakce řídí sama (každý krok průvodce je atomický);
                    // obalit ji by znamenalo, že se chyba uprostřed kroku nevrátí.
                    if ($dryRun || $key === HistoricalYearCloser::STEP) {
                        $fn();
                    } else {
                        $this->transactional(function () use ($frameworkGuard, $supplierId, $frameworkPeriods, $fn): void {
                            $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                            $fn();
                        });
                    }
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof MoneyS3Exception || $e instanceof AccountingFrameworkException) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        // Text výjimky (SQL, cesty) do protokolu viditelného v UI nepatří.
                        error_log(sprintf('Money S3: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
                        $protocol->error($key, 'unexpected', 'Krok převodu selhal na neočekávané chybě, podrobnosti jsou v logu serveru.');
                    }
                    $protocol->fail($key);
                    break;
                }
                if (in_array($key, self::CRITICAL_STEPS, true) && $protocol->blocks($key)) {
                    $protocol->fail($key);
                    break;
                }
            }
            $ctx->report('done', $total, $total);
            if (!$dryRun) {
                $this->invoices->recomputeClientStats($ctx);
            }

            if (!$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $userId > 0 ? $userId : null);
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            $protocol->set('automation', $automation);
        } catch (AccountingFrameworkException $e) {
            $protocol->error(self::STEP_PREFLIGHT, $e->errorCode, $e->getMessage());
            $protocol->fail(self::STEP_PREFLIGHT);
        } finally {
            if ($savepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(ImportContext $ctx): array
    {
        $plan = $this->journal->plan($ctx->backup, $ctx->options, $ctx->calendar);
        $first = $plan[0];
        $seedDate = $this->periods->findByYear($ctx->supplierId, $first['year'])['starts_on'] ?? $first['starts_on'];
        return [
            ChartImporter::STEP => fn () => $this->chart->run($ctx, $seedDate),
            JournalImporter::STEP => fn () => $this->journal->run($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            CodebookImporter::STEP_PARTNERS => fn () => $this->codebooks->importPartners($ctx),
            CodebookImporter::STEP_POSTING_RULES => fn () => $this->codebooks->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => fn () => $this->linker->link($ctx),
            DimensionImporter::STEP => fn () => $this->dimensions->run($ctx),
            DocumentLinker::STEP_PAYMENTS => fn () => $this->linker->matchPayments($ctx),
            RecurringSeriesTakeover::STEP => fn () => $this->takeOverRecurring($ctx),
            AssetImporter::STEP =>fn () => $this->assets->importLongTerm($ctx),
            AssetImporter::STEP_SMALL => fn () => $this->assets->importSmall($ctx),
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->run($ctx),
            HistoricalYearCloser::STEP => fn () => $this->closer->run($ctx),
            PayrollImporter::STEP => fn () => $this->payroll->run($ctx),
            MoneyS3Reconciler::STEP => fn () => $this->reconciler->run($ctx),
        ];
    }

    private function takeOverRecurring(ImportContext $ctx): void
    {
        $result = $this->recurring->run($ctx->supplierId, $ctx->userId);
        $ctx->protocol->setCount(RecurringSeriesTakeover::STEP, 'created', $result['created']);
        if ($result['existing'] > 0) {
            $ctx->protocol->setCount(RecurringSeriesTakeover::STEP, 'existing', $result['existing']);
        }
        if ($result['templates'] !== []) {
            $ctx->protocol->info(RecurringSeriesTakeover::STEP, 'paused_templates', sprintf(
                'Z pravidelných faktur v historii vzniklo %d pozastavených šablon pravidelné fakturace (%s). Nic se samo nevystaví: zkontrolujte je v Pravidelné fakturace a spusťte.',
                count($result['templates']),
                implode(', ', array_map(static fn (array $t): string => $t['client'], array_slice($result['templates'], 0, 10))) . (count($result['templates']) > 10 ? ', …' : ''),
            ), ['templates' => array_slice($result['templates'], 0, 50)]);
        }
    }

    private function switchMode(ImportContext $ctx): void
    {
        $starts = array_map(static fn (array $p): string => $p['starts_on'], $ctx->periods);
        if ($starts === []) {
            return;
        }
        sort($starts);
        $ends = array_map(static fn (array $p): string => $p['ends_on'], $ctx->periods);
        $this->unit->switchToDoubleEntry($ctx->supplierId, $starts[0], !$ctx->options->isDryRun(), max($ends));
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $starts[0] . '.');
    }


    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
