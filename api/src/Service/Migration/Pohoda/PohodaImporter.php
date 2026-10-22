<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder;
use PDO;

/**
 * Převod agendy Pohody (XML export jednoho účetního roku) do existující firmy v MyÚčtu.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * přijaté a vydané doklady → pokladna a banka → vazby dokladů na deník a úhrady →
 * majetek → sklad → koeficient krácení odpočtu (§ 76) → rekonciliace. Automatika účtování je po celou dobu vypnutá ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí.
 * **Ostrý převod** zapisuje po krocích, každý krok je idempotentní ({@see PohodaImportRepository}):
 * opakovaný běh téhož nebo novějšího exportu nic nezdvojí.
 */
final class PohodaImporter
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat. */
    private const CRITICAL_STEPS = [ChartJournalImporter::STEP_CHART, ChartJournalImporter::STEP_JOURNAL, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartJournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly PartnerImporter $partners,
        private readonly InvoiceImporter $invoices,
        private readonly OtherItemImporter $otherItems,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly PohodaReconciler $reconciler,
        private readonly AssetImporter $assets,
        private readonly SmallAssetImporter $smallAssets,
        private readonly TableStatistics $statistics,
        private readonly UnbookedBankPayments $unbooked,
        private readonly VatCoefficientSeeder $coefficients,
        private readonly StockImporter $stock,
    ) {}

    /** @return list<string> */
    public static function stepKeys(): array
    {
        return [
            ChartJournalImporter::STEP_CHART,
            ChartJournalImporter::STEP_JOURNAL,
            self::STEP_ACCOUNTING_MODE,
            PartnerImporter::STEP_PARTNERS,
            PartnerImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            InvoiceImporter::STEP_INTERNAL,
            OtherItemImporter::STEP,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DocumentLinker::STEP_PAYMENTS,
            AssetImporter::STEP,
            SmallAssetImporter::STEP,
            StockImporter::STEP,
            VatCoefficientSeeder::STEP,
            PohodaReconciler::STEP,
        ];
    }

    /** Kalendář účetního roku firmy - podle něj se agendy řadí do jejích účetních období. */
    public function companyCalendar(int $supplierId): FiscalCalendar
    {
        return $this->periods->fiscalCalendar($supplierId);
    }

    /**
     * Kontrola před převodem - nic nezapisuje. Chyba převod zastaví, upozornění ne.
     * `$skipYears` = roky po roce agendy, které se nepřevádějí ({@see PohodaContext::$skippedYears}).
     * `$laterYears` = pozdější roky agendy z přehledu v `meta.json` (náhled průvodce); bez
     * nich se spočítají průchodem celým deníkem.
     *
     * `$agendaYear` = období agendy podle kalendáře firmy; bez něj se určí z deníku.
     *
     * @param list<int> $skipYears
     * @param list<int>|null $laterYears
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, PohodaExport $export, array $skipYears = [], ?array $laterYears = null, ?PohodaAgendaYear $agendaYear = null): array
    {
        $out = $export->sourceWarnings();
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
        $supplierIco = PartnerImporter::ico((string) ($supplier['ic'] ?? ''));
        if ($export->ico === '' || $supplierIco === '' || $export->ico !== $supplierIco) {
            // Export cizí firmy by se jinak vmíchal do účetnictví téhle.
            $add('error', 'ico_mismatch', "Export je agenda IČO {$export->ico}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $export->ico, 'supplier' => $supplierIco]);
        }
        foreach ($export->files() as $f) {
            if (!$f['exists']) {
                continue;
            }
            if ($f['ico'] !== '' && $f['ico'] !== $export->ico) {
                $add('error', 'file_ico_mismatch', "Soubor {$f['file']} je z agendy IČO {$f['ico']}, ne {$export->ico}.", ['file' => $f['file']]);
            } elseif ($f['state'] !== 'ok') {
                $add(in_array($f['key'], ['journal', 'chart', 'vat_classes'], true) ? 'error' : 'info', 'file_state',
                    "Soubor {$f['file']}: Pohoda vrátila stav „{$f['state']}“" . ($f['note'] !== '' ? " ({$f['note']})" : '') . '.', ['file' => $f['file']]);
            }
        }
        // Rok agendy musí sedět na účetní rok firmy: hospodářský rok v kalendářních obdobích
        // (nebo obráceně) by se rozdělil do dvou období a převod se zastaví s návrhem nastavení.
        $agendaYear ??= PohodaAgendaYear::of($export, $this->periods->fiscalCalendar($supplierId));
        try {
            $agendaYear->assertMatchesCompany();
        } catch (FiscalYearMismatchException $e) {
            $add('error', 'fiscal_year_mismatch', $e->getMessage(), ['suggested_start_month' => $e->suggestedStartMonth]);
        }
        $agendaLabel = $agendaYear->label;
        // Agenda POHODY vede i doklady po konci roku - jejich zápisy jdou do období podle
        // skutečného data, takže do rozjetého účetnictví se nesmí přimíchat ani tam. Pozdější
        // roky z přehledu exportu jsou kalendářní (náhled firmu nezná); u hospodářského roku
        // firmy se proto počítají znovu podle jejího kalendáře.
        $allLaterYears = $laterYears !== null && $agendaYear->calendar->isCalendar() ? array_map('intval', $laterYears) : $agendaYear->laterYears();
        $laterYears = array_values(array_diff($allLaterYears, $skipYears));
        $skipped = array_values(array_intersect($allLaterYears, $skipYears));
        try {
            $guard = new AccountingFrameworkImportGuard($this->db);
            $guard->assertCz500Periods($supplierId, $guard->periodsForYears($supplierId, [$agendaLabel, ...$laterYears]));
        } catch (AccountingFrameworkException $e) { $add('error', $e->errorCode, $e->getMessage()); }

        $journalMapped = $this->map->hasAny($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        foreach (array_merge([$agendaLabel], $laterYears) as $year) {
            $period = $this->periods->findByYear($supplierId, $year);
            if ($period === null) {
                continue;
            }
            $mapped = $this->map->get($supplierId, PohodaImportRepository::KIND_PERIOD, (string) $year) !== null || $journalMapped;
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$year} už obsahuje {$foreign} zápisů, které nevznikly převodem z Pohody. Deník z Pohody se do rozjetého účetnictví nepřimíchává.", ['entries' => $foreign, 'year' => $year]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$year} je v MyÚčtu uzavřené.", ['year' => $year]);
            }
        }
        if ($laterYears !== []) {
            $add('info', 'later_periods', sprintf(
                'Agenda %d obsahuje doklady s datem v roce %s. Jejich zápisy se převedou do účetního období podle skutečného data (chybějící období se založí otevřené); rok %d zůstane neuzavřený, uzávěrku a převod zůstatků provede účetní.',
                $agendaLabel, implode(', ', $laterYears), $agendaLabel,
            ), ['years' => $laterYears]);
        }
        if ($skipped !== []) {
            $add('info', 'later_years_skipped', sprintf(
                'Rok %s se nepřevádí: zápisy deníku, pohyby v bance a pokladně, doklady a úhrady s datem v něm převod přeskočí. Opakovaný převod s vybraným rokem je doplní, nic nezdvojí.',
                implode(', ', $skipped),
            ), ['years' => $skipped]);
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        if ($export->path('payroll') !== null) {
            $add('info', 'payroll_separate', 'Export obsahuje mzdy z datového souboru POHODY (91_mzdy.xml). Převod účetnictví je nepřevádí, zaměstnanci a mzdy se převádějí zvlášť.');
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param list<int> $skipYears roky po roce agendy, které se nepřevádějí
     * @param bool $acceptDifferences ostrý převod přijme rozdíly k přijetí ({@see ImportProtocol::difference()})
     * @param array{warehouses:array<string,string>}|null $stock převod skladu zvolený v průvodci ({@see PohodaContext::$stock})
     */
    public function run(int $supplierId, int $userId, PohodaExport $export, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, array $skipYears = [], bool $acceptDifferences = false, ?array $stock = null): ImportProtocol
    {
        // Tvar roku firmy se určí před prvním zápisem: převod podle něj zakládá období.
        $agendaYear = PohodaAgendaYear::of($export, $this->periods->fiscalCalendar($supplierId));
        if ($skipYears !== []) {
            $skipYears = array_values(array_intersect($agendaYear->laterYears(), $skipYears));
        }
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import', $acceptDifferences);
        $protocol->set('agenda', [
            'ico' => $export->ico,
            'year' => $export->year,
            'program' => $export->info['program'],
            'exported_at' => $export->info['timestamp'],
            'dir' => basename($export->dir),
            'skipped_years' => $skipYears,
        ]);
        $preflight = $this->preflight($supplierId, $export, $skipYears, null, $agendaYear);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            } elseif ($m['level'] === 'warning') {
                $protocol->warn(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
                $protocol->count(self::STEP_PREFLIGHT, 'skipped_documents');
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $ctx = new PohodaContext($supplierId, $userId, $export, PohodaVat::fromExport($export), $dryRun, $protocol);
        $ctx->useAgendaYear($agendaYear);
        $ctx->runId = $runId;
        $ctx->progress = $progress;
        $ctx->skippedYears = array_fill_keys($skipYears, true);
        $ctx->stock = $stock;

        $frameworkGuard = new AccountingFrameworkImportGuard($this->db);
        $frameworkPeriods = $frameworkGuard->periodsForYears($supplierId, [$agendaYear->label, ...array_values(array_diff($agendaYear->laterYears(), $skipYears))]);
        $pdo = $this->db->pdo();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes savepoint -
        // vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT pohoda_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = ($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId);
            if (!$dryRun) {
                $frameworkGuard->writeCz500Periods($supplierId, $frameworkPeriods, function () use ($runId, $supplierId, $snapshot, $userId, $ctx): void {
                    if ($runId !== null) $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
                    $this->unit->disableAutomation($supplierId, $ctx->userOrNull());
                });
            } else {
                $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];

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
                if (!$dryRun && $key === PohodaReconciler::STEP) {
                    $this->statistics->refreshAfterImport(['pohoda_import_map']);
                }
                try {
                    $dryRun ? $fn() : $this->transactional(function () use ($frameworkGuard, $supplierId, $frameworkPeriods, $fn): void {
                        $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                        $fn();
                    });
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof PohodaException || $e instanceof AccountingFrameworkException) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        // Text výjimky (SQL, cesty) do protokolu viditelného v UI nepatří.
                        error_log(sprintf('POHODA: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
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
                $this->unit->restoreAutomation($supplierId, $snapshot, $ctx->userOrNull());
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
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(PohodaContext $ctx): array
    {
        return [
            ChartJournalImporter::STEP_CHART => fn () => $this->journal->chart($ctx),
            ChartJournalImporter::STEP_JOURNAL => fn () => $this->journal->journal($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            PartnerImporter::STEP_PARTNERS => fn () => $this->partners->importPartners($ctx),
            PartnerImporter::STEP_POSTING_RULES => fn () => $this->partners->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            InvoiceImporter::STEP_INTERNAL => fn () => $this->invoices->importInternalTaxDocuments($ctx),
            OtherItemImporter::STEP => fn () => $this->otherItems->run($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => function () use ($ctx): void {
                $this->unbooked->supersede($ctx);
                $this->linker->link($ctx);
            },
            DocumentLinker::STEP_PAYMENTS => function () use ($ctx): void {
                $this->linker->matchPayments($ctx);
                $this->unbooked->settle($ctx);
            },
            AssetImporter::STEP => fn () => $this->assets->import($ctx),
            SmallAssetImporter::STEP => fn () => $this->smallAssets->import($ctx),
            StockImporter::STEP => fn () => $this->stock->import($ctx),
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->seedConverted(
                $ctx->supplierId,
                $this->vatYears($ctx, array_keys($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PERIOD))),
                $this->vatYears($ctx, [$ctx->year(), ...array_keys(array_filter($ctx->periods))]),
                $ctx->userId,
                $ctx->protocol,
            ),
            PohodaReconciler::STEP => fn () => $this->reconciler->run($ctx),
        ];
    }

    /**
     * Koeficient § 76 je za KALENDÁŘNÍ rok (DPH), ne za účetní období: období hospodářského
     * roku zasahuje do dvou kalendářních let. Firma s kalendářním rokem předá labely beze změny.
     *
     * @param list<int|string> $periodYears labely účetních období
     * @return list<int|string>
     */
    private function vatYears(PohodaContext $ctx, array $periodYears): array
    {
        if ($ctx->calendar->isCalendar()) {
            return $periodYears;
        }
        $supplierId = $ctx->supplierId;
        $years = [];
        foreach (array_map('intval', $periodYears) as $label) {
            $period = $this->periods->findByYear($supplierId, $label);
            if ($period === null) {
                $years[$label] = true;
                continue;
            }
            for ($y = (int) substr((string) $period['starts_on'], 0, 4); $y <= (int) substr((string) $period['ends_on'], 0, 4); $y++) {
                $years[$y] = true;
            }
        }
        ksort($years);
        return array_keys($years);
    }

    private function switchMode(PohodaContext $ctx): void
    {
        if ($ctx->period === null) {
            return;
        }
        $this->unit->switchToDoubleEntry($ctx->supplierId, $ctx->period['starts_on'], !$ctx->dryRun, $ctx->lastPeriodEnd() ?? $ctx->period['ends_on']);
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $ctx->period['starts_on'] . '.');
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
