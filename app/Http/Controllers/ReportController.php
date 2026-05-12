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
            ->view('reports.export-print', $payload)
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

        return [
            'title' => trim($validated['title']),
            'generatedAt' => $validated['generatedAt'] ?? now()->format('Y-m-d H:i:s'),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function safeFilename(string $title): string
    {
        $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title));
        return trim($filename ?: 'report', '-');
    }
}
