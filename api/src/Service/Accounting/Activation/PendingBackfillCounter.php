<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TakenOverRecord;

final class PendingBackfillCounter
{
    public function __construct(private readonly Connection $db) {}

    public function count(int $supplierId, ?string $from = null): array
    {
        $pdo = $this->db->pdo();
        $cashDate = $from !== null ? ' AND issue_date >= ?' : '';
        $docDate = $from !== null ? ' AND COALESCE(tax_date, issue_date) >= ?' : '';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM cash_documents
              WHERE supplier_id = ? AND status = 'posted' AND journal_entry_id IS NULL{$cashDate}
                AND " . OpeningBalanceDocuments::notInOpeningSql('cash', 'cash_documents')
        );
        $stmt->execute($from !== null ? [$supplierId, $from] : [$supplierId]);
        $cashDocuments = (int) $stmt->fetchColumn();

        $invoiceTypePlaceholders = implode(',', array_fill(0, count(PostingService::POSTABLE_ISSUED_INVOICE_TYPES), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices i
              WHERE i.supplier_id = ? AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type IN ({$invoiceTypePlaceholders}){$docDate}
                AND " . OpeningBalanceDocuments::notInOpeningSql('invoice', 'i') . "
                AND NOT " . TakenOverRecord::documentSql('invoice', 'i') . "
                AND NOT " . DocumentPostingGroup::activePostingExistsSql("'invoice'", 'i.id', 'i.supplier_id', false)
        );
        $invoiceParams = array_merge([$supplierId], PostingService::POSTABLE_ISSUED_INVOICE_TYPES);
        if ($from !== null) $invoiceParams[] = $from;
        $stmt->execute($invoiceParams);
        $invoices = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.status IN ('received','booked','paid'){$docDate}
                AND pi.document_kind <> 'advance'
                AND " . OpeningBalanceDocuments::notInOpeningSql('purchase_invoice', 'pi') . "
                AND NOT " . TakenOverRecord::documentSql('purchase_invoice', 'pi') . "
                AND NOT " . DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id', false)
        );
        $stmt->execute($from !== null ? [$supplierId, $from] : [$supplierId]);
        $purchaseInvoices = (int) $stmt->fetchColumn();

        $bankFrom = $from !== null ? ' AND bt.posted_at >= ?' : '';
        $bankSql = "SELECT COUNT(DISTINCT bt.id)
                      FROM bank_transactions bt
                      JOIN bank_statements bs ON bs.id = bt.statement_id
                     WHERE bt.source = 'statement' AND bt.match_status <> 'ignored'{$bankFrom}
                       AND " . \MyInvoice\Service\Bank\BankTransactionPostingScope::noMigrationReviewSql('bt') . "
                       AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                        WHERE je.supplier_id = ? AND je.source_type = 'bank'
                                          AND je.source_id = bt.id AND je.reversed_by IS NULL)
                       AND (
                           " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql() . "
                           OR EXISTS (SELECT 1 FROM invoice_payments ip
                                       WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                           OR EXISTS (SELECT 1 FROM payment_matches pm
                                       WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id)
                           OR EXISTS (SELECT 1 FROM invoices i
                                       WHERE i.supplier_id = ? AND i.id = bt.matched_invoice_id)
                       )";
        $params = [];
        if ($from !== null) $params[] = $from;
        // Pořadí: je.supplier_id, resolver (2×), ip, pm, i — SEC-01 nahradil
        // jeden „EXISTS currencies" placeholder dvěma z resolveru.
        $params[] = $supplierId;
        array_push($params, ...\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId));
        array_push($params, $supplierId, $supplierId, $supplierId);
        $stmt = $pdo->prepare($bankSql);
        $stmt->execute($params);
        $bankTransactions = (int) $stmt->fetchColumn();

        // Zápočty proti účtu bez zápisu. Evidovaná úhrada, o které deník neví: doklad tvrdí
        // „uhrazeno", saldokontní účet je ale otevřený a v detailu chybí proklik na zaúčtování.
        // Vzniká v daňové evidenci (deník tam není) a při hromadném přeúčtování, které zápočty
        // neumělo — `journal_entry_id` má ON DELETE SET NULL, takže vazba tiše zmizí.
        $settlementDate = $from !== null ? ' AND settled_on >= ?' : '';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoice_settlements
              WHERE supplier_id = ? AND status = 'confirmed'
                AND journal_entry_id IS NULL{$settlementDate}"
        );
        $stmt->execute($from !== null ? [$supplierId, $from] : [$supplierId]);
        $settlements = (int) $stmt->fetchColumn();

        return [
            'cash_documents' => $cashDocuments,
            'invoices' => $invoices,
            'purchase_invoices' => $purchaseInvoices,
            'bank_transactions' => $bankTransactions,
            'settlements' => $settlements,
            'total' => $cashDocuments + $invoices + $purchaseInvoices + $bankTransactions + $settlements,
        ];
    }
}
