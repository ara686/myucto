<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;

/**
 * Interní doklady POHODY v agendě Účetnictví → Interní doklady. Účetně jsou už v deníku
 * ({@see ChartJournalImporter}), tady nad jejich zápisem vznikne hlavička stejnou cestou jako
 * akce „Vytvořit interní doklad ze zápisu". Deník ani výkazy se nemění.
 *
 * Běží po navázání dokladů: zápis, který patří k zaúčtování faktury, řídí faktura a hlavičku
 * nedostane. Opakovaný převod doplní jen chybějící doklady a srovná datum přesunutého zápisu.
 */
final class InternalDocumentImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly InternalDocumentService $documents,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $entryIds = [];
        foreach ($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            if ((explode('|', (string) $key, 3)[1] ?? '') === PohodaJournal::INTERNAL) {
                $entryIds[] = (int) $entryId;
            }
        }
        $step = DocumentLinker::STEP_PAYMENTS;
        $unnumbered = 0;
        $refused = [];
        foreach (array_chunk($entryIds, 500) as $chunk) {
            foreach ($this->entries($ctx->supplierId, $chunk) as $entry) {
                if ($entry['document_id'] !== null) {
                    $this->alignDate($ctx->supplierId, $entry);
                    $ctx->protocol->count($step, 'internal_documents_existing');
                    continue;
                }
                if (trim((string) $entry['document_no']) === '') {
                    $unnumbered++;
                    continue;
                }
                try {
                    $created = $this->documents->createFromEntry($ctx->supplierId, (int) $entry['id'], [
                        'kind' => 'other',
                        'title' => self::title((string) $entry['description']),
                    ], ['user_id' => $ctx->userOrNull()]);
                } catch (InternalDocumentException $e) {
                    $refused[$e->errorCode][] = (string) $entry['document_no'];
                    continue;
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_INTERNAL_DOCUMENT, (string) $entry['id'], (int) $created['document']['id'], $ctx->runId);
                $ctx->protocol->count($step, 'internal_documents');
            }
        }
        if ($unnumbered > 0) {
            $ctx->protocol->warn($step, 'internal_document_without_number', "{$unnumbered} interních dokladů POHODY nemá číslo, zůstávají jen v deníku.", ['entries' => $unnumbered]);
        }
        foreach ($refused as $code => $numbers) {
            $ctx->protocol->warn($step, 'internal_document_not_created', sprintf(
                'Interní doklad nevznikl (%s) u %d zápisů, zůstávají jen v deníku: %s.',
                $code, count($numbers), implode(', ', array_slice($numbers, 0, 10)) . (count($numbers) > 10 ? ', …' : ''),
            ), ['code' => $code, 'documents' => array_slice($numbers, 0, 200)]);
        }
    }

    /**
     * Zápisy, které zůstaly ruční. Interní doklad převzatý jako daňový doklad k platbě nebo
     * oprava navázaná na doklad má vlastní agendu a do výběru nepatří.
     *
     * @param list<int> $entryIds
     * @return list<array<string,mixed>>
     */
    private function entries(int $supplierId, array $entryIds): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.document_no, e.description, e.entry_date, d.id AS document_id, d.entry_date AS document_entry_date
               FROM journal_entries e
               LEFT JOIN internal_documents d ON d.supplier_id = e.supplier_id AND d.journal_entry_id = e.id
              WHERE e.supplier_id = ? AND e.source_type = 'manual' AND e.source_id IS NULL
                AND e.id IN (" . implode(',', array_fill(0, count($entryIds), '?')) . ')
              ORDER BY e.id'
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $entry */
    private function alignDate(int $supplierId, array $entry): void
    {
        if ($entry['entry_date'] !== $entry['document_entry_date']) {
            $this->db->pdo()->prepare('UPDATE internal_documents SET entry_date = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$entry['entry_date'], $entry['document_id'], $supplierId]);
        }
    }

    /** Popis zápisu bez úvodní agendy a čísla ({@see ChartJournalImporter}), číslo má doklad zvlášť. */
    private static function title(string $description): string
    {
        return (string) preg_replace('/^' . preg_quote(PohodaJournal::INTERNAL, '/') . '\s+\S*\s*—\s*/u', '', $description);
    }
}
