<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} Report</title>
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
        .schedule-report th { height: 42px; }
        .schedule-report td { height: 58px; padding: 4px; }
        .schedule-report td:first-child { width: 54px; min-width: 54px; color: #64748b; font-size: 10px; font-weight: 600; text-align: right; background: #f8fafc; }
        .schedule-badge { min-height: 46px; height: 100%; display: flex; align-items: center; justify-content: center; border-left: 3px solid; border-radius: 7px; padding: 6px; font-size: 9px; line-height: 1.25; font-weight: 700; text-align: center; white-space: normal; }
        .schedule-badge-0 { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
        .schedule-badge-1 { background: #ecfdf5; border-color: #10b981; color: #047857; }
        .schedule-badge-2 { background: #f5f3ff; border-color: #8b5cf6; color: #6d28d9; }
        .schedule-badge-3 { background: #fffbeb; border-color: #f59e0b; color: #b45309; }
        .schedule-badge-4 { background: #fff1f2; border-color: #f43f5e; color: #be123c; }
        .schedule-badge-5 { background: #ecfeff; border-color: #06b6d4; color: #0e7490; }
        @media print {
            .actions { display: none; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <section class="report-header">
        <h1>{{ $title }} Report</h1>
        <div class="generated">Generated: {{ $generatedAt }}</div>
    </section>

    @php($isSchedulesReport = str_contains(strtolower($title), 'schedule'))

    @if (count($rows) === 0)
        <div class="empty">No report data available.</div>
    @else
        <table class="{{ $isSchedulesReport ? 'schedule-report' : '' }}">
            @if (!empty($headers))
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>
                                @if ($isSchedulesReport && !$loop->first && trim((string) $cell) !== '')
                                    <div class="schedule-badge schedule-badge-{{ ($loop->parent->index + $loop->index) % 6 }}">
                                        {{ $cell }}
                                    </div>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

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
