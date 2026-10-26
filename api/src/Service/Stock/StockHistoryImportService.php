<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use PDO;

/**
 * Import historických skladových dokladů z jiného skladového programu (vzor importu
 * počátečních zásob: zdroj → náhled → aplikace, obojí jako úloha na pozadí).
 *
 * Dva režimy podle `cutover_date` (D):
 *  - historie před stavem v MyÚčtu: firma už má skladové doklady od D (typicky příjemku
 *    počátečních zásob). Historie musí skončit před D a k D−1 ji uzavře vyrovnávací
 *    výdejka celého zůstatku, takže stavy, ocenění i doklady od D zůstanou beze změny,
 *  - historie je živý stav (bez D): firma žádné skladové doklady nemá a stav skladu
 *    po importu je konec historie.
 *
 * Firma má nejvýš jeden aktivní import; další jde až po vrácení předchozího.
 * Doklady se neúčtují (skladové doklady deník nikdy nezapisují), nečerpají číselnou
 * řadu, nevytvářejí rezervace ani nemění faktury, jen se na ně odkazují.
 */
final class StockHistoryImportService
{
    public const STAGE_KIND = 'stock_history_import_stage';
    public const APPLY_KIND = 'stock_history_import_apply';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogImportSourceStore $sources,
    ) {}

    public function preview(int $supplierId, int $sourceId, array $config, ?int $createdBy): array
    {
        $source = $this->sources->get($supplierId, $sourceId);
        $profile = StockHistoryImportProfile::normalize($config);
        $this->assertMode($supplierId, $profile);
        $id = $this->jobs->enqueue($supplierId, self::STAGE_KIND, [
            'source_id' => $sourceId,
            'source_sha256' => $source['sha256'],
            'profile' => $profile,
        ], StockHistoryImportProfile::MAX_ROWS, createdBy: $createdBy);
        return $this->jobs->find($supplierId, $id);
    }

    public function apply(int $supplierId, int $previewId, ?int $createdBy, bool $confirmClosedPeriods = false): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Stock history apply requires its own transaction.');
        }
        $pdo->beginTransaction();
        try {
            // Zámek firmy serializuje souběžná spuštění (jeden aktivní import na firmu).
            $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE')->execute([$supplierId]);
            $stmt = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $previewId]);
            $source = $stmt->fetchColumn() === false ? null : $this->jobs->find($supplierId, $previewId);
            if ($source === null || $source['kind'] !== self::STAGE_KIND) {
                throw new EshopException('not_found', 'Náhled importu historie nenalezen.', 404);
            }
            $report = $source['report'];
            if ($source['status'] !== 'completed' || (($report['counts']['failed'] ?? 0) + ($report['counts']['conflict'] ?? 0)) > 0) {
                throw new EshopException('job_state_conflict', 'Náhled obsahuje chyby nebo ještě není dokončen.', 409);
            }
            if (($report['counts']['ready'] ?? 0) === 0) {
                throw new EshopException('history_import_empty', 'Náhled neobsahuje žádný doklad k převzetí.', 409);
            }
            $profile = $source['input']['profile'];
            if (($report['negative_count'] ?? 0) > 0 && !$profile['fill_negative']) {
                throw new EshopException('history_import_negative_stock', 'Historie vede k zápornému stavu zásob. Doplňte chybějící příjem, nebo zapněte doplnění technickými příjemkami.', 409);
            }
            if (($report['closed_period_documents'] ?? 0) > 0 && !$confirmClosedPeriods) {
                throw new EshopException('history_import_closed_periods_unconfirmed', 'Část dokladů spadá do uzavřeného účetního období. Potvrďte, že je chcete převzít.', 409);
            }
            $this->assertMode($supplierId, $profile);

            $id = $this->jobs->enqueue($supplierId, self::APPLY_KIND, $source['input'] + [
                'source_job_id' => $previewId,
                'date_from' => $report['date_from'],
                'date_to' => $report['date_to'],
            ], createdBy: $createdBy);
            // Pořadí aplikace = chronologie: datum, pak pořadí prvního řádku dokladu v souboru.
            // Doklady tak dostanou rostoucí id ve stejném pořadí, v jakém je čte skladová kniha.
            $stmt = $pdo->prepare("INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, input_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY doc_date, first_ordinal, ordinal), stock_item_id, source_row, input_json
                FROM (SELECT ordinal, stock_item_id, source_row, input_json,
                        JSON_VALUE(input_json, '$.values.doc_date') AS doc_date,
                        MIN(ordinal) OVER (PARTITION BY JSON_VALUE(input_json, '$.doc')) AS first_ordinal
                    FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready') staged");
            $stmt->execute([$supplierId, $id, $supplierId, $previewId]);
            $total = $stmt->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $id]);

            $cutover = $profile['cutover_date'];
            $lockedUntil = $cutover === null ? (string) $report['date_to'] : self::dayBefore($cutover);
            $pdo->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')->execute([$lockedUntil, $supplierId]);
            if ($cutover !== null) {
                // Nulový snapshot k D−1: přepočet živých dokladů (zpětný doklad od D) začne
                // za historií i během importu, kdy ještě není vyrovnaná.
                $pdo->prepare("INSERT INTO stock_valuation_snapshots
                    (supplier_id, warehouse_id, stock_item_id, cutoff_date, qty, value_total, source_version)
                    SELECT ?, legs.warehouse_id, legs.stock_item_id, ?, 0, 0, w.valuation_version
                    FROM (SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_id') AS UNSIGNED) AS warehouse_id, stock_item_id
                            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                          UNION
                          SELECT DISTINCT CAST(JSON_VALUE(input_json, '$.values.warehouse_to_id') AS UNSIGNED), stock_item_id
                            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                              AND JSON_VALUE(input_json, '$.values.doc_type') = 'transfer') legs
                    JOIN warehouses w ON w.id = legs.warehouse_id AND w.supplier_id = ?
                    ON DUPLICATE KEY UPDATE qty = 0, value_total = 0, algorithm_version = 1, created_at = NOW(6)")
                    ->execute([$supplierId, $lockedUntil, $supplierId, $id, $supplierId, $id, $supplierId]);
            }
            $pdo->commit();
            return $this->jobs->find($supplierId, $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Poslední aplikace importu historie, jejíž doklady ve firmě zůstaly (nebo právě běží). */
    public function active(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT GREATEST(
                COALESCE((SELECT MAX(id) FROM catalog_jobs WHERE supplier_id = ? AND kind = ? AND status IN ('queued','running')), 0),
                COALESCE((SELECT MAX(history_import_job_id) FROM stock_documents WHERE supplier_id = ?), 0))");
        $stmt->execute([$supplierId, self::APPLY_KIND, $supplierId]);
        $id = (int) $stmt->fetchColumn();
        return $id === 0 ? null : $this->jobs->find($supplierId, $id);
    }

    public static function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Režim musí sedět se skladem firmy: bez D jen firma bez skladových dokladů, s D jen
     * tak, aby historie skončila před prvním dokladem vzniklým v MyÚčtu.
     */
    private function assertMode(int $supplierId, array $profile): void
    {
        if ($this->active($supplierId) !== null) {
            throw new EshopException('history_import_active', 'Firma už má převzatou historii skladu. Další import je možný až po jejím vrácení.', 409);
        }
        $stmt = $this->db->pdo()->prepare("SELECT MIN(doc_date) FROM stock_documents
            WHERE supplier_id = ? AND status IN ('posted','reversed') AND history_import_job_id IS NULL");
        $stmt->execute([$supplierId]);
        $first = $stmt->fetchColumn();
        if ($profile['cutover_date'] === null && is_string($first)) {
            throw new EshopException('history_import_cutover_required', 'Firma už má skladové doklady. Zadejte datum přechodu, před kterým historie končí.', 422);
        }
        if ($profile['cutover_date'] !== null && is_string($first) && $first < $profile['cutover_date']) {
            throw new EshopException('history_import_cutover_after_documents', 'Datum přechodu nesmí být pozdější než první skladový doklad v MyÚčtu (' . $first . ').', 422);
        }
    }
}
