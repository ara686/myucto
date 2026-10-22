<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Pdf\StereoNxProtocolPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class StereoNxProtocolPdfRendererTest extends TestCase
{
    public function testFailedDryRunIncludesAllCountsAndReviewDetailsWithoutInternalData(): void
    {
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($this->data());
        self::assertStringContainsString('Neúspěšné', $html);
        self::assertStringContainsString('Plánovaný zápis', $html);
        self::assertStringContainsString('tento protokol nepotvrzuje zápis dat', $html);
        self::assertStringContainsString('31.08.2026', $html);
        self::assertStringContainsString('Drobný majetek - ve zdroji', $html);
        self::assertStringContainsString('>0</td>', $html);
        self::assertStringContainsString('SYNTHETIC-REVIEW-1', $html);
        self::assertStringContainsString('Syntetická tabulka', $html);
        self::assertStringNotContainsString('PRIVATE-KEY-NEVER-PRINT', $html);
        self::assertStringNotContainsString('PRIVATE-PARAM-NEVER-PRINT', $html);
        self::assertStringContainsString('&lt;img src=&quot;https://example.invalid/image&quot;&gt;', $html);
        self::assertStringNotContainsString('<img ', $html);
    }

    public function testEnglishImportDistinguishesWrittenResultAndReconciliation(): void
    {
        $data = $this->data();
        $data['mode'] = 'import';
        $data['language'] = 'en';
        $data['report']['ok'] = true;
        $data['report']['database_writes'] = true;
        $data['report']['reconciliation'] = [['year' => 2026, 'ok' => false,
            'checks' => [['key' => 'stereo_nx_journal', 'ok' => false]],
            'journal_diffs' => [['account' => '501', 'myucto' => [0, 125.5, 125.5], 'money' => [0, 125.0, 125.0]]]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('Write results', $html);
        self::assertStringContainsString('08/31/2026', $html);
        self::assertStringContainsString('Does not match', $html);
        self::assertStringContainsString('0.00 / 125.50 / 125.50', $html);
        self::assertStringNotContainsString('this report does not confirm', $html);
    }

    public function testInfoMessageDoesNotTurnSuccessfulRunIntoWarning(): void
    {
        $data = ['report' => ['ok' => true, 'preflight' => [['level' => 'info', 'message' => 'Synthetic information']]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('<strong>Dokončeno</strong>', $html);
        self::assertStringNotContainsString('Dokončeno s upozorněními', $html);
    }

    public function testReconstructedItemsInfoInWarningsCollectionRemainsInformational(): void
    {
        $data = ['report' => ['ok' => true, 'warnings' => [['level' => 'info',
            'code' => 'document_items_reconstructed', 'message' => 'Syntetické řádky vytvořeny z deníku.']]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('<strong>Dokončeno</strong>', $html);
        self::assertStringNotContainsString('Dokončeno s upozorněními', $html);
        self::assertStringContainsString('Syntetické řádky vytvořeny z deníku.', $html);
    }

    public function testPdfContainsDiacriticsAndCompleteLongProtocol(): void
    {
        $data = $this->data();
        $data['report']['warnings'] = array_map(static fn (int $number): array => ['level' => 'warning',
            'message' => 'České upozornění číslo ' . $number . ': ' . str_repeat('Dlouhý syntetický důvod vyžadující ruční kontrolu. ', 3)], range(1, 70));
        $bytes = (new StereoNxProtocolPdfRenderer())->render($data);
        self::assertStringStartsWith('%PDF-', $bytes);
        $pdf = (new Parser())->parseContent($bytes);
        self::assertGreaterThan(1, count($pdf->getPages()));
        $text = $pdf->getText();
        self::assertStringContainsString('České upozornění číslo 70', $text);
        self::assertStringContainsString('SYNTHETIC-REVIEW-1', $text);
        self::assertStringNotContainsString('PRIVATE-KEY-NEVER-PRINT', $text);
    }

    private function data(): array
    {
        return ['mode' => 'dry_run', 'job_id' => 123, 'company_name' => 'Syntetická firma',
            'created_at' => '2026-08-31 12:30:00', 'params' => ['password' => 'PRIVATE-PARAM-NEVER-PRINT'],
            'report' => ['ok' => false, 'date_bounds' => ['from' => '2026-01-01', 'to' => '2026-08-31'],
                'counts' => ['assets_source' => 1, 'assets_ready' => 0, 'assets_skipped' => 1, 'small_assets_source' => 0],
                'written' => ['assets_created' => 0], 'partial' => true,
                'errors' => ['Synthetic failure <img src="https://example.invalid/image">'],
                'warnings' => [['level' => 'warning', 'code' => 'asset_tax_method_unsupported', 'message' => 'Syntetická zpráva']],
                'review_reasons' => ['vat_participation_unassigned' => 1],
                'review_documents' => [['kind' => 'purchase', 'document_no' => 'SYNTHETIC-REVIEW-1',
                    'source_key' => 'PRIVATE-KEY-NEVER-PRINT', 'review_codes' => ['vat_participation_unassigned']]],
                'not_transferred' => [['table' => 'Syntetická tabulka', 'count' => 2]],
            ]];
    }
}
