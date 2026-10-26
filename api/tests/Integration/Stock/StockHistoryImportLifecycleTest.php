<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Eshop\CatalogJobAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockHistoryImportService;
use MyInvoice\Service\Stock\StockHistoryImportWorker;
use MyInvoice\Service\Stock\StockReportService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Životní cyklus importu historie skladu: souběh s živými doklady, opakované a zastaralé
 * úlohy, osiřelá historie a shoda živého stavu s firmou bez importu.
 */
final class StockHistoryImportLifecycleTest extends StockTestCase
{
    private const HEADER = 'doklad;typ;datum;sklad;sklad_cil;sku;mnozstvi;cena;partner;faktura_vydana;faktura_prijata;poznamka';

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    /** Dvě shodné firmy, jedna s importem historie: stavy a ocenění od D se shodují i po vrácení. */
    public function testCutoverImportKeepsLiveStateEqualToControlCompany(): void
    {
        $build = function (): array {
            $sid = $this->createSupplier();
            $hl = $this->warehouse($sid, 'HL');
            $pob = $this->warehouse($sid, 'POB', false);
            $a = $this->item($sid, 'ZB-100');
            $b = $this->item($sid, 'ZB-200');
            $this->receiveStock($sid, $hl, $a, '10', 100.0, '2021-01-01');
            $this->receiveStock($sid, $hl, $b, '7', 33.33, '2021-01-01');
            $this->post($sid, 'issue', $hl, null, '2021-02-01', [[$a, '3']]);
            $this->post($sid, 'transfer', $hl, $pob, '2021-02-15', [[$a, '2'], [$b, '1']]);
            $this->post($sid, 'issue', $hl, null, '2021-03-01', [[$b, '2.5']]);
            return [$sid, [$hl, $pob], [$a, $b]];
        };
        [$control, $controlWarehouses, $controlItems] = $build();
        [$sid, $warehouses, $items] = $build();
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));

        $csv = self::csv([
            'P1;prijemka;2020-01-05;HL;;ZB-100;10;50.123;;;;',
            'P1;prijemka;2020-01-05;HL;;ZB-200;3;7;;;;',
            'V1;vydejka;2020-02-01;HL;;ZB-100;4;;;;;',
            'T1;prevodka;2020-03-01;HL;POB;ZB-100;3;;;;;',
            'T1;prevodka;2020-03-01;HL;POB;ZB-200;5;;;;;',
            'V2;vydejka;2020-04-01;POB;;ZB-200;5;;;;;',
            'P2;prijemka;2020-05-01;HL;;ZB-100;1;77.77;;;;',
            'V3;vydejka;2020-12-31;HL;;ZB-100;1;;;;;',
            'I1;prebytek;2020-12-31;POB;;ZB-100;0.333;12.5;;;;',
        ]);
        $preview = $this->stage($sid, $csv, $this->config('2021-01-01', ['fill_negative' => true]));
        self::assertSame('completed', $preview['status']);
        $service = $this->container->get(StockHistoryImportService::class);
        $job = $service->apply($sid, $preview['id'], $this->userId);
        $this->container->get(StockHistoryImportWorker::class)->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        // Zpětný živý doklad během importu (historie zapsaná, ještě neoceněná).
        $this->post($sid, 'issue', $warehouses[0], null, '2021-01-20', [[$items[1], '1']]);
        $this->post($control, 'issue', $controlWarehouses[0], null, '2021-01-20', [[$controlItems[1], '1']]);
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));
        self::assertSame([], $this->container->get(StockReportService::class)->valuation($sid, '2020-12-31', [])['items']);
        self::assertNotSame([], $this->container->get(StockReportService::class)->valuation($sid, '2020-06-30', [])['items']);

        $this->post($sid, 'issue', $warehouses[0], null, '2021-03-05', [[$items[0], '1']]);
        $this->post($control, 'issue', $controlWarehouses[0], null, '2021-03-05', [[$controlItems[0], '1']]);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));
        self::assertSame(0, $this->historyCount($sid));
    }

    /** Zastaralé vrácení (zrušené, pak spuštěné znovu po novém importu) nesmí sáhnout na nový import. */
    public function testRetriedStaleRollbackCannotTouchNewerImport(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');

        $applied = $this->applyAll($sid, $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config(null))['id']);
        $stale = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertTrue($jobs->cancel($sid, $stale['id']));
        $this->db->pdo()->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')->execute(['2020-01-05', $sid]);
        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        $newer = $this->applyAll($sid, $this->stage($sid, self::csv(['P9;receipt;2020-01-05;HL;;ZB-100;4;10;;;;']), $this->config(null))['id']);
        self::assertSame('completed', $newer['status']);

        self::assertSame(409, $this->retryThroughAction($sid, $stale['id']));
        self::assertTrue($jobs->retry($sid, $stale['id']));
        $this->expectStale(fn () => $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $stale['id']));
        self::assertSame('failed', $jobs->find($sid, $stale['id'])['status']);
        self::assertSame(['qtyT' => 4000, 'valueC' => 4000], $this->level($sid, $main, $a));
        self::assertSame(1, $this->historyCount($sid));
        self::assertSame('2020-01-05', $this->lockedUntil($sid));
    }

    /** Zrušená aplikace po vrácení nejde dopsat: zbytek historie by vznikl bez zámku. */
    public function testRetriedApplyAfterRollbackWritesNothing(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $worker = $this->container->get(StockHistoryImportWorker::class);
        $sid = $this->createSupplier();
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = sprintf('P%04d;receipt;2020-01-%02d;HL;;ZB-100;1;10;;;;', $i, 1 + intdiv($i, 30));
        }
        $job = $service->apply($sid, $this->stage($sid, self::csv($rows), $this->config(null))['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertTrue($jobs->cancel($sid, $job['id']));
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertSame('cancelled', $jobs->find($sid, $job['id'])['status']);
        $rollback = $service->rollback($sid, $job['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertNull($this->lockedUntil($sid));

        self::assertSame(409, $this->retryThroughAction($sid, $job['id']));
        self::assertTrue($jobs->retry($sid, $job['id']));
        $this->expectStale(fn () => $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']));
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
    }

    /** Bez data přechodu: během aplikace i vrácení nejde zaúčtovat živý doklad (žádná slepá ulička). */
    public function testLiveModeLocksWholeStockWhileApplyingAndRollingBack(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $worker = $this->container->get(StockHistoryImportWorker::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;', 'V1;issue;2020-02-01;HL;;ZB-100;3;;;;;']), $this->config(null));
        $job = $service->apply($sid, $preview['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        $this->expectLocked(fn () => $this->receiveStock($sid, $main, $a, '1', 5.0, '2021-05-01'));
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame('2020-02-01', $this->lockedUntil($sid));

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::ROLLBACK_KIND, 1);
        $this->expectLocked(fn () => $this->post($sid, 'issue', $main, null, '2021-05-01', [[$a, '4']]));
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->level($sid, $main, $a));
        self::assertNull($this->lockedUntil($sid));
        $this->receiveStock($sid, $main, $a, '1', 5.0, '2021-05-01');
    }

    /**
     * Historie bez úlohy (obnova z archivu nepřenáší catalog_jobs): další import odmítne,
     * správce ji vrátí podle převzatých dokladů a živý stav zůstane.
     */
    public function testOrphanHistoryBlocksNewImportAndCanBeRolledBack(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $level = $this->level($sid, $main, $a);
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;']), $this->config('2021-01-01'))['id']);
        $this->db->pdo()->prepare('DELETE FROM catalog_job_items WHERE job_id = ?')->execute([$applied['id']]);
        $this->db->pdo()->prepare('DELETE FROM catalog_jobs WHERE id = ?')->execute([$applied['id']]);

        self::assertNull($service->active($sid));
        self::assertSame($applied['id'], $service->orphan($sid));
        try {
            $this->stage($sid, self::csv(['Q1;prijemka;2020-03-05;HL;;ZB-100;2;10;;;;']), $this->config('2020-06-01'));
            self::fail('Druhý import nad osiřelou historií musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_active', $e->errorCode);
        }

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('2021-01-01', $rollback['input']['cutover_date']);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
        self::assertSame($level, $this->level($sid, $main, $a));
        self::assertNull($service->orphan($sid));
    }

    /** Vrácení převzatých dokladů v uzavřeném období chce stejné potvrzení jako jejich převzetí. */
    public function testRollbackInClosedPeriodNeedsConfirmation(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier('double_entry');
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        $applied = $service->apply($sid, $preview['id'], $this->userId, true);
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $applied['id']);
        try {
            $service->rollback($sid, $applied['id'], $this->userId);
            self::fail('Vrácení v uzavřeném období bez potvrzení musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
        }
        $rollback = $service->rollback($sid, $applied['id'], $this->userId, true);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function expectStale(callable $run): void
    {
        try {
            $run();
            self::fail('Zastaralá úloha musí selhat.');
        } catch (StockException $e) {
            self::assertSame('history_import_job_stale', $e->errorCode);
        }
    }

    private function expectLocked(callable $run): void
    {
        try {
            $run();
            self::fail('Doklad během importu historie musí selhat.');
        } catch (StockException $e) {
            self::assertSame('stock_history_locked', $e->errorCode);
        }
    }

    private function retryThroughAction(int $supplierId, int $jobId): int
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/history-import/jobs/' . $jobId . '/retry')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $this->container->get(CatalogJobAction::class)->change($request, new Response(), ['id' => $jobId, 'operation' => 'retry']);
        if ($response->getStatusCode() === 409) {
            self::assertSame('history_import_retry_unsupported', json_decode((string) $response->getBody(), true)['error']['code']);
        }
        return $response->getStatusCode();
    }

    private function post(int $supplierId, string $type, int $warehouseId, ?int $warehouseToId, string $date, array $lines): array
    {
        $document = $this->documents->create($supplierId, [
            'doc_type' => $type, 'warehouse_id' => $warehouseId, 'warehouse_to_id' => $warehouseToId, 'doc_date' => $date, 'description' => 'Živý doklad',
            'lines' => array_map(static fn (array $line): array => ['stock_item_id' => $line[0], 'qty' => $line[1]], $lines),
        ], $this->userId);
        return $this->documents->post($supplierId, (int) $document['id'], $this->userId);
    }

    /** Stavy, živé řádky a ocenění k několika dnům od data přechodu. */
    private function fingerprint(int $supplierId, array $warehouses, array $items): array
    {
        $out = [];
        foreach ($warehouses as $w => $warehouse) {
            foreach ($items as $i => $item) {
                $out["level {$w}/{$i}"] = $this->level($supplierId, $warehouse, $item);
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT d.doc_date, d.doc_type, l.qty, l.unit_cost, l.value_total FROM stock_documents d
            JOIN stock_document_lines l ON l.document_id = d.id WHERE d.supplier_id = ? AND d.history_import_job_id IS NULL ORDER BY d.id, l.line_no');
        $stmt->execute([$supplierId]);
        $out['live lines'] = $stmt->fetchAll(\PDO::FETCH_NUM);
        $report = $this->container->get(StockReportService::class);
        foreach (['2021-01-01', '2021-01-31', '2021-02-15', '2021-03-02', '2021-12-31'] as $date) {
            $out["valuation {$date}"] = array_map(
                static fn (array $row): array => [$row['warehouse_code'], $row['sku'], $row['qty'], $row['value_total']],
                $report->valuation($supplierId, $date, [])['items'],
            );
        }
        return $out;
    }

    private function historyCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function lockedUntil(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    private static function csv(array $rows): string
    {
        return self::HEADER . "\n" . implode("\n", $rows) . "\n";
    }

    private function config(?string $cutover, array $over = []): array
    {
        return $over + [
            'source_key' => 'stary-sklad',
            'cutover_date' => $cutover,
            'mapping' => [
                'doc_number' => 'doklad', 'doc_type' => 'typ', 'doc_date' => 'datum', 'warehouse' => 'sklad',
                'warehouse_to' => 'sklad_cil', 'sku' => 'sku', 'quantity' => 'mnozstvi', 'unit_cost' => 'cena',
                'partner' => 'partner', 'invoice_number' => 'faktura_vydana', 'purchase_invoice_number' => 'faktura_prijata',
                'note' => 'poznamka',
            ],
        ];
    }

    private function stage(int $supplierId, string $csv, array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'history-lifecycle-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'historie.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $job = $this->container->get(StockHistoryImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->drain($supplierId, StockHistoryImportService::STAGE_KIND, $job['id']);
    }

    private function applyAll(int $supplierId, int $previewId): array
    {
        $job = $this->container->get(StockHistoryImportService::class)->apply($supplierId, $previewId, $this->userId);
        return $this->drain($supplierId, StockHistoryImportService::APPLY_KIND, $job['id']);
    }

    private function drain(int $supplierId, string $kind, int $jobId): array
    {
        $worker = $this->container->get(StockHistoryImportWorker::class);
        for ($i = 0; $i < 50; $i++) {
            $job = $worker->tickKind($supplierId, $kind);
            if ($job === null || in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
                break;
            }
        }
        $job = $this->container->get(CatalogJobService::class)->find($supplierId, $jobId);
        self::assertNotNull($job);
        return $job;
    }
}
