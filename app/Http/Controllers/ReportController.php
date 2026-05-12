<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faculty;
use App\Models\FacultyLoading;
use App\Models\Subject;

class ReportController extends Controller
{
    /**
     * Retrieves the key performance indicators for the reports page.
     * Includes Total Faculty, Assigned Subjects, and Total Units Loaded.
     */
    public function getKpiData(Request $request)
    {
        $totalFaculty = Faculty::where('status', 0)->count();
        $assignedSubjectsCount = FacultyLoading::distinct('subject_id')->count('subject_id');
        $totalUnitsLoaded = Subject::whereIn('id', function ($query) {
            $query->select('subject_id')
                  ->from('faculty_loadings')
                  ->distinct();
        })->sum('total_units');

        return response()->json([
            'success' => true,
            'data' => [
                'totalFaculty' => $totalFaculty,
                'assignedSubjects' => $assignedSubjectsCount,
                'totalUnitsLoaded' => (int) $totalUnitsLoaded,
            ],
            'message' => 'KPI data retrieved successfully.'
        ], 200);
    }

    public function exportCsv(Request $request)
    {
        $payload = $this->normalizeExportPayload($request);
        $filename = $this->safeFilename($payload['title']) . '-report.csv';

        return response()->streamDownload(function () use ($payload) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [$payload['title'] . ' Report']);
            fputcsv($handle, ['Generated: ' . $payload['generatedAt']]);
            fputcsv($handle, []);

            if (!empty($payload['headers'])) {
                fputcsv($handle, $payload['headers']);
            }

            foreach ($payload['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPrint(Request $request)
    {
        $payload = $this->normalizeExportPayload($request);

        return response()
            ->make($this->renderPrintHtml($payload))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function normalizeExportPayload(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'generatedAt' => ['nullable', 'string', 'max:120'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string'],
            'rows' => ['required', 'array'],
            'rows.*' => ['array'],
            'rows.*.*' => ['nullable'],
            'cells' => ['nullable', 'array'],
            'cells.*' => ['array'],
            'cells.*.*.text' => ['nullable'],
            'cells.*.*.rowSpan' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cells.*.*.colSpan' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $headers = collect($validated['headers'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->values()
            ->all();

        $rows = collect($validated['rows'] ?? [])
            ->map(fn ($row) => collect($row)->map(fn ($value) => trim((string) $value))->values()->all())
            ->filter(fn ($row) => count(array_filter($row, fn ($value) => $value !== '')) > 0)
            ->values()
            ->all();

        $cells = collect($validated['cells'] ?? [])
            ->map(fn ($row) => collect($row)->map(fn ($cell) => [
                'text' => trim((string) ($cell['text'] ?? '')),
                'rowSpan' => max(1, (int) ($cell['rowSpan'] ?? 1)),
                'colSpan' => max(1, (int) ($cell['colSpan'] ?? 1)),
            ])->values()->all())
            ->filter(fn ($row) => count($row) > 0)
            ->values()
            ->all();

        return [
            'title' => trim($validated['title']),
            'generatedAt' => $validated['generatedAt'] ?? now()->format('Y-m-d H:i:s'),
            'headers' => $headers,
            'rows' => $rows,
            'cells' => $cells,
        ];
    }

    private function safeFilename(string $title): string
    {
        $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title));
        return trim($filename ?: 'report', '-');
    }

    private function renderPrintHtml(array $payload): string
    {
        $title = $this->escapeHtml($payload['title']);
        $generatedAt = $this->escapeHtml($payload['generatedAt']);
        $headers = $payload['headers'] ?? [];
        $rows = !empty($payload['cells']) ? $payload['cells'] : $payload['rows'];
        $isSchedulesReport = str_contains(strtolower($payload['title']), 'schedule');
        $isLoadingReport = str_contains(strtolower($payload['title']), 'loading');
        $tableClass = $isSchedulesReport ? 'schedule-report' : '';

        $headHtml = '';
        if (!empty($headers)) {
            $headHtml .= '<thead><tr>';
            foreach ($headers as $header) {
                $headHtml .= '<th>' . $this->escapeHtml($header) . '</th>';
            }
            $headHtml .= '</tr></thead>';
        }

        $bodyHtml = '';
        foreach ($rows as $rowIndex => $row) {
            $bodyHtml .= '<tr>';
            foreach ($row as $cellIndex => $cell) {
                $cellText = is_array($cell) ? ($cell['text'] ?? '') : $cell;
                $rowSpan = is_array($cell) ? max(1, (int) ($cell['rowSpan'] ?? 1)) : 1;
                $colSpan = is_array($cell) ? max(1, (int) ($cell['colSpan'] ?? 1)) : 1;
                $isCovered = $isLoadingReport && trim((string) $cellText) === '';
                $class = $isCovered ? ' class="covered-cell"' : '';

                $bodyHtml .= '<td' . $class . ' rowspan="' . $rowSpan . '" colspan="' . $colSpan . '">';
                if ($isSchedulesReport && $cellIndex > 0 && trim((string) $cellText) !== '') {
                    $slots = $this->durationSlots((string) $cellText);
                    $height = max(46, ($slots * 58) - 8);
                    $colorIndex = ($rowIndex + $cellIndex) % 6;
                    $bodyHtml .= '<div class="schedule-badge schedule-badge-' . $colorIndex . '" style="height:' . $height . 'px;">'
                        . $this->escapeHtml($cellText)
                        . '</div>';
                } else {
                    $bodyHtml .= $this->escapeHtml($cellText === '' ? ' ' : $cellText);
                }
                $bodyHtml .= '</td>';
            }
            $bodyHtml .= '</tr>';
        }

        $contentHtml = count($rows) === 0
            ? '<div class="empty">No report data available.</div>'
            : '<table class="' . $tableClass . '">' . $headHtml . '<tbody>' . $bodyHtml . '</tbody></table>';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} Report</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #0f172a; background: #fff; }
        .report-header { padding: 12px 14px; margin-bottom: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
        h1 { margin: 0; font-size: 18px; line-height: 1.25; }
        .generated { margin-top: 4px; color: #475569; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; table-layout: auto; font-size: 11px; }
        thead { display: table-header-group; }
        th { background: #eef2ff; color: #3730a3; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        th, td { border: 1px solid #cbd5e1; padding: 7px 8px; vertical-align: middle; text-align: center; }
        td:first-child, th:first-child { text-align: left; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        tr { page-break-inside: avoid; }
        .empty { padding: 24px; text-align: center; color: #64748b; border: 1px solid #cbd5e1; }
        .actions { margin-top: 12px; display: flex; gap: 8px; }
        button { border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; padding: 8px 12px; cursor: pointer; }
        .schedule-report { width: 100%; max-width: 100%; table-layout: fixed; }
        .schedule-report th { height: 40px; padding: 5px 4px; font-size: 9px; line-height: 1.15; }
        .schedule-report td { height: 52px; padding: 3px; overflow: visible; position: relative; }
        .schedule-report th:not(:first-child),
        .schedule-report td:not(:first-child) { width: auto; }
        .schedule-report td:first-child,
        .schedule-report th:first-child { width: 48px; min-width: 48px; max-width: 48px; color: #64748b; font-size: 9px; font-weight: 600; text-align: right; background: #f8fafc; }
        .schedule-badge { position: absolute; inset: 3px; z-index: 2; display: flex; align-items: center; justify-content: center; border-left: 3px solid; border-radius: 6px; padding: 4px; font-size: 8px; line-height: 1.2; font-weight: 700; text-align: center; white-space: normal; }
        .schedule-badge-0 { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
        .schedule-badge-1 { background: #ecfdf5; border-color: #10b981; color: #047857; }
        .schedule-badge-2 { background: #f5f3ff; border-color: #8b5cf6; color: #6d28d9; }
        .schedule-badge-3 { background: #fffbeb; border-color: #f59e0b; color: #b45309; }
        .schedule-badge-4 { background: #fff1f2; border-color: #f43f5e; color: #be123c; }
        .schedule-badge-5 { background: #ecfeff; border-color: #06b6d4; color: #0e7490; }
        .covered-cell { border-top-color: transparent !important; border-bottom-color: transparent !important; color: transparent; }
        @media print {
            .actions { display: none; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <section class="report-header">
        <h1>{$title} Report</h1>
        <div class="generated">Generated: {$generatedAt}</div>
    </section>
    {$contentHtml}
    <div class="actions">
        <button onclick="window.print()">Print / Save as PDF</button>
        <button onclick="window.close()">Close</button>
    </div>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
HTML;
    }

    private function durationSlots(string $cell): int
    {
        if (!preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)\s*-\s*(\d{1,2}):(\d{2})\s*(am|pm)/i', $cell, $matches)) {
            return 1;
        }

        $start = $this->timeToMinutes((int) $matches[1], (int) $matches[2], $matches[3]);
        $end = $this->timeToMinutes((int) $matches[4], (int) $matches[5], $matches[6]);

        if ($end <= $start) {
            return 1;
        }

        return max(1, (int) ceil(($end - $start) / 60));
    }

    private function timeToMinutes(int $hour, int $minute, string $period): int
    {
        $period = strtolower($period);
        if ($period === 'pm' && $hour !== 12) {
            $hour += 12;
        }
        if ($period === 'am' && $hour === 12) {
            $hour = 0;
        }

        return ($hour * 60) + $minute;
    }

    private function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
