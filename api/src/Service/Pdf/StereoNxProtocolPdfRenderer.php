<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Bootstrap;

/** Export uloženého výsledku; nikdy znovu nečte zdrojový ZIP ani parametry jobu. */
final class StereoNxProtocolPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $view = $this->view($data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 12, 'margin_right' => 12]);
        $mpdf->SetTitle($view['labels']['title']);
        $mpdf->SetFooter($view['labels']['page'] . ' {PAGENO} ' . $view['labels']['of'] . ' {nbpg}||' . $view['labels']['created_by']);
        ChunkedHtmlWriter::write($mpdf, $this->renderHtml($data));
        return $mpdf->Output('', 'S');
    }

    public function renderHtml(array $data): string
    {
        return $this->renderTemplate('stereo_nx_protocol.twig', $this->view($data));
    }

    /** Do tiskového modelu přenášíme jen zobrazované údaje, nikoli interní identifikátory. */
    private function view(array $data): array
    {
        $lang = ($data['language'] ?? '') === 'en' ? 'en' : 'cs';
        $catalog = json_decode((string) file_get_contents(Bootstrap::rootDir() . '/api/resources/pdf/stereo-nx-protocol.' . $lang . '.json'), true, flags: JSON_THROW_ON_ERROR);
        $labels = $catalog['ui'];
        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $mode = ($data['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $number = static fn (mixed $value, int $decimals = 0): string => is_numeric($value)
            ? number_format((float) $value, $decimals, $lang === 'cs' ? ',' : '.', $lang === 'cs' ? ' ' : ',') : '-';
        $label = static fn (string $group, string $key): string => $catalog[$group][$key] ?? str_replace('_', ' ', $key);
        $sections = [];
        foreach (['counts' => 'source_counts', 'written' => $mode === 'dry_run' ? 'planned' : 'written'] as $key => $title) {
            $rows = [];
            foreach (($report[$key] ?? []) as $metric => $value) {
                if (is_int($value) || is_float($value)) $rows[] = [$label('counts', (string) $metric), $number($value)];
            }
            if ($rows !== []) $sections[] = ['title' => $labels[$title], 'headers' => [$labels['metric'], $labels['count']], 'rows' => $rows];
        }
        $messages = [];
        foreach (['preflight' => 'info', 'errors' => 'error', 'warnings' => 'warning'] as $key => $fallback) {
            foreach (($report[$key] ?? []) as $item) {
                $text = is_string($item) ? $item : (is_array($item) && is_string($item['message'] ?? null) ? $item['message'] : '');
                if ($text === '') continue;
                $level = is_array($item) && isset($catalog['level'][$item['level'] ?? '']) ? $item['level'] : $fallback;
                $document = is_array($item) && is_string($item['document_no'] ?? null) ? $item['document_no'] : '';
                $messages[] = [$label('level', $level), $document, $text];
            }
        }
        if ($messages !== []) $sections[] = ['title' => $labels['messages'], 'headers' => [$labels['level'], $labels['document'], $labels['message']], 'rows' => $messages];
        foreach (['review_reasons', 'movement_review_reasons'] as $key) {
            $rows = [];
            foreach (($report[$key] ?? []) as $reason => $count) $rows[] = [$label('reasons', (string) $reason), $number($count)];
            if ($rows !== []) $sections[] = ['title' => $labels[$key === 'review_reasons' ? 'document_reasons' : 'movement_reasons'], 'headers' => [$labels['reason'], $labels['count']], 'rows' => $rows];
        }
        $rows = [];
        foreach (['review_documents', 'review_movements'] as $key) {
            foreach (($report[$key] ?? []) as $record) {
                $reasons = array_map(static fn ($code): string => $label('reasons', (string) $code), $record['review_codes'] ?? []);
                $rows[] = [$label('kind', (string) ($record['kind'] ?? '')), (string) ($record['document_no'] ?? '') ?: $labels['unknown_document'], implode('; ', $reasons)];
            }
        }
        if ($rows !== []) $sections[] = ['title' => $labels['documents_review'], 'headers' => [$labels['metric'], $labels['document'], $labels['reason']], 'rows' => $rows];
        $rows = [];
        foreach (($report['not_transferred'] ?? []) as $table) $rows[] = [(string) ($table['table'] ?? ''), $number($table['count'] ?? null)];
        if ($rows !== []) $sections[] = ['title' => $labels['not_transferred'], 'headers' => [$labels['table'], $labels['count']], 'rows' => $rows];
        foreach (($report['reconciliation'] ?? []) as $year) {
            $title = $labels['reconciliation'] . ' - ' . $labels['year'] . ' ' . ($year['year'] ?? '') . ' - ' . $labels[($year['ok'] ?? false) ? 'ok' : 'not_ok'];
            $rows = [];
            foreach (($year['checks'] ?? []) as $check) $rows[] = [$label('checks', (string) ($check['key'] ?? '')), $labels[($check['ok'] ?? false) ? 'ok' : 'not_ok']];
            $sections[] = ['title' => $title, 'headers' => [$labels['metric'], $labels['message']], 'rows' => $rows];
            $rows = [];
            foreach (($year['journal_diffs'] ?? []) as $diff) {
                $triple = static fn (array $values): string => implode(' / ', array_map(static fn ($value): string => $number($value, 2), $values));
                $rows[] = [(string) ($diff['account'] ?? ''), $triple($diff['myucto'] ?? []), $triple($diff['money'] ?? [])];
            }
            if ($rows !== []) $sections[] = ['title' => $title, 'headers' => [$labels['account'], $labels['target'], $labels['source']], 'rows' => $rows];
        }
        $hasWarnings = !empty($report['warnings']) || !empty($report['review_reasons']) || !empty($report['movement_review_reasons']) || !empty($report['partial']);
        foreach (($report['preflight'] ?? []) as $item) {
            if (is_array($item) && ($item['level'] ?? '') === 'warning') $hasWarnings = true;
        }
        foreach ($sections as &$section) {
            $section['numeric_last'] = end($section['headers']) === $labels['count'];
            $section['widths'] = count($section['headers']) === 2
                ? ($section['numeric_last'] ? [82, 18] : [65, 35])
                : ($section['headers'][0] === $labels['level'] ? [15, 22, 63]
                    : ($section['headers'][0] === $labels['account'] ? [20, 40, 40] : [20, 25, 55]));
        }
        unset($section);
        return ['labels' => $labels, 'language' => $lang, 'sections' => $sections,
            'company' => (string) ($data['company_name'] ?? ''), 'job_id' => (int) ($data['job_id'] ?? 0),
            'created' => $this->date((string) ($data['created_at'] ?? ''), $lang, true),
            'from' => $this->date((string) ($report['date_bounds']['from'] ?? ''), $lang),
            'to' => $this->date((string) ($report['date_bounds']['to'] ?? ''), $lang),
            'mode' => $catalog['mode'][$mode], 'dry_run' => $mode === 'dry_run',
            'database_writes' => ($report['database_writes'] ?? false) === true,
            'partial' => !empty($report['partial']), 'failed' => ($report['ok'] ?? false) !== true,
            'status' => $catalog['status'][($report['ok'] ?? false) !== true ? 'failed' : ($hasWarnings ? 'completed_with_warnings' : 'completed')],
        ];
    }

    private function date(string $date, string $lang, bool $time = false): string
    {
        if ($date === '') return '';
        try {
            return (new \DateTimeImmutable($date))->format(($lang === 'en' ? 'm/d/Y' : 'd.m.Y') . ($time ? ' H:i' : ''));
        } catch (\Exception) {
            return '';
        }
    }
}
