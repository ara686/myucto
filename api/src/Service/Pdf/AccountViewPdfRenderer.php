<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF rozvahy nebo výsledovky po účtech (A4 portrait).
 *
 * Data = výstup FinancialStatementService::accountView() doplněný o `part`
 * (balance|profit_loss) a `unit` (czk|thousands).
 */
final class AccountViewPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        if (\MyInvoice\Service\Accounting\Reports\Statement504Presentation::accepts($data)) {
            return (new Statement504PdfRenderer())->render($data);
        }
        \MyInvoice\Service\Accounting\Reports\StatementExportPolicy::assertSupported($data);
        $title = ($data['part'] ?? 'balance') === 'profit_loss' ? 'Výsledovka po účtech' : 'Rozvaha po účtech';
        $body = $this->renderTemplate('account_view.twig', $data + ['title' => $title]);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle($title . ' ' . (string) ($data['period']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, $title);
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
