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
        <h1>{{ $title }} Report</h1>
        <div class="generated">Generated: {{ $generatedAt }}</div>
    </section>

    @php($isSchedulesReport = str_contains(strtolower($title), 'schedule'))
    @php($isLoadingReport = str_contains(strtolower($title), 'loading'))

    @php
        $durationSlots = function ($cell) {
            if (!preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)\s*-\s*(\d{1,2}):(\d{2})\s*(am|pm)/i', (string) $cell, $m)) {
                return 1;
            }

            $toMinutes = function ($hour, $minute, $period) {
                $hour = (int) $hour;
                $minute = (int) $minute;
                $period = strtolower($period);
                if ($period === 'pm' && $hour !== 12) $hour += 12;
                if ($period === 'am' && $hour === 12) $hour = 0;
                return ($hour * 60) + $minute;
            };

            $start = $toMinutes($m[1], $m[2], $m[3]);
            $end = $toMinutes($m[4], $m[5], $m[6]);
            if ($end <= $start) return 1;
            return max(1, (int) ceil(($end - $start) / 60));
        };
    @endphp

    @php($printRows = !empty($cells) ? $cells : $rows)

    @if (count($printRows) === 0)
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
                @foreach ($printRows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            @php($cellText = is_array($cell) ? ($cell['text'] ?? '') : $cell)
                            @php($rowSpan = is_array($cell) ? ($cell['rowSpan'] ?? 1) : 1)
                            @php($colSpan = is_array($cell) ? ($cell['colSpan'] ?? 1) : 1)
                            <td
                                class="{{ $isLoadingReport && trim((string) $cellText) === '' ? 'covered-cell' : '' }}"
                                rowspan="{{ $rowSpan }}"
                                colspan="{{ $colSpan }}"
                            >
                                @if ($isSchedulesReport && !$loop->first && trim((string) $cellText) !== '')
                                    @php($slots = $durationSlots($cellText))
                                    <div class="schedule-badge schedule-badge-{{ ($loop->parent->index + $loop->index) % 6 }}" style="height: {{ max(46, ($slots * 58) - 8) }}px;">
                                        {{ $cellText }}
                                    </div>
                                @elseif ($isLoadingReport && trim((string) $cellText) === '')
                                    &nbsp;
                                @else
                                    {{ $cellText }}
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
