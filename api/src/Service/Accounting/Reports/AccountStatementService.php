<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Opis účtu (Epic F2): stránkovaný výpis pohybů účtu (vč. analytik pod
 * syntetikou) s běžícím zůstatkem. PS k `from` dle okna R6; balance = kladné = MD.
 * Řazení entry_date, entry_id, line_no (deterministické) — running_delta počítá
 * window funkce nad celým rozsahem, takže stránkování zůstatky nemění.
 */
final class AccountStatementService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalLineContext $context,
    ) {}

    /**
     * @return array<string,mixed> struktura dle spec §2.6
     */
    public function build(int $supplierId, int $accountId, string $from, string $to, int $page, int $perPage, bool $afterClosing = false): array
    {
        $account = $this->accounts->findById($supplierId, $accountId, $to);
        if ($account === null) {
            throw new ReportException('account_not_found', 'Účet #' . $accountId . ' neexistuje.', 404);
        }

        $period = $this->periods->findForDate($supplierId, $from);
        $periodStart = $period !== null ? (string) $period['starts_on'] : $from;

        // Stejný filtr technických zápisů musí dostat PS i pohyby, jinak se
        // otevírací zápis započítá dvakrát a KS vyjde jako dvojnásobek PS.
        $excludeClosing = !$afterClosing;

        $opening = $this->ledger->accountOpening($supplierId, $accountId, $from, $periodStart, $excludeClosing);
        $total   = $this->ledger->accountLinesTotal($supplierId, $accountId, $from, $to, $excludeClosing);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $lines = $this->ledger->accountLines($supplierId, $accountId, $from, $to, $perPage, ($page - 1) * $perPage, $excludeClosing);

        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'entry_id'    => (int) $l['entry_id'],
                'entry_date'  => (string) $l['entry_date'],
                'document_no' => $l['document_no'],
                'description' => $l['description'],
                'source_type' => (string) $l['source_type'],
                'source_id'   => $l['source_id'],
                'side'        => (string) $l['side'],
                'amount'      => JournalLineAmount::signed($l),
                'balance'     => round($opening + (float) $l['running_delta'], 2),
                // Účet ŘÁDKU — u syntetiky je opis složený z analytik, bez tohohle
                // sloupce nešlo poznat, na které z nich pohyb visí.
                'account_id'   => (int) $l['line_account_id'],
                'account_code' => (string) $l['account_code'],
                'account_name' => (string) $l['line_account_name'],
                // Drill-down na prvotní doklad — shodná sada polí jako v deníku
                // (JournalEntryRepository::paginate), aby proklik vedl na tentýž doklad.
                'source_statement_id'       => $l['source_statement_id'],
                'source_bank_ref'           => $l['source_bank_ref'] ?? null,
                'source_doc_number'       => $l['source_doc_number'],
                'source_register_id'        => $l['source_register_id'],
                'source_asset_id'           => $l['source_asset_id'],
                'source_asset_name'         => $l['source_asset_name'],
                'source_settlement_doc_type' => $l['source_settlement_doc_type'],
                'source_settlement_doc_id'  => $l['source_settlement_doc_id'],
                'line_id'        => (int) $l['line_id'],
                'line_no'        => (int) $l['line_no'],
                'currency_code'  => $l['currency_code'],
                'amount_foreign' => $l['amount_foreign'] === null ? null : JournalLineAmount::signed([
                    'amount' => $l['amount_foreign'], 'is_red_storno' => $l['is_red_storno'],
                ]),
            ];
        }
        $items = $this->context->enrich($supplierId, $items);

        $turnovers = $this->ledger->accountTurnovers($supplierId, $accountId, $from, $to, $excludeClosing);

        return [
            'account' => [
                'id'          => (int) $account['id'],
                'code'        => (string) $account['account_code'],
                'name'        => (string) $account['name'],
                'type'        => (string) $account['account_type'],
                'normal_side' => $account['normal_side'],
            ],
            'opening_balance' => $opening,
            'items'           => $items,
            'total'           => $total,
            'page'            => $page,
            'per_page'        => $perPage,
            'closing_balance' => round($opening + $turnovers['md'] - $turnovers['d'], 2),
            'turnover_md'     => $turnovers['md'],
            'turnover_d'      => $turnovers['d'],
        ];
    }
}
