<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    @php($wide = count($report->columns) > 12)
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $wide ? '6.5px' : '9.5px' }}; color: #111; }
        h1 { font-size: 14px; margin: 0; }
        .muted { color: #6b7280; }
        /* Wide laporan (e.g. Tutup Buku, 20 columns): share the page width instead of overflowing it. */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; {{ $wide ? 'table-layout: fixed;' : '' }} }
        th { background: #047857; color: #fff; padding: 4px {{ $wide ? '2px' : '5px' }}; text-align: left; word-wrap: break-word; }
        td { padding: 3px {{ $wide ? '2px' : '5px' }}; border-bottom: 1px solid #e5e7eb; word-wrap: break-word; }
        th:first-child, td:first-child { width: {{ $wide ? '44px' : 'auto' }}; white-space: nowrap; }
        .r { text-align: right; {{ $wide ? '' : 'white-space: nowrap;' }} }
        tr.section td { background: #ecfdf5; font-weight: bold; }
        tr.total td { font-weight: bold; border-top: 2px solid #111; }
        tr.muted td { color: #6b7280; }
        td.warn { color: #b91c1c; font-weight: bold; }
        .notes { margin-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ $company }} · {{ $report->title }}</h1>
    <div class="muted">{{ $report->subtitle }} · printed {{ now()->format('d/m/Y H:i') }}@if ($header) · {{ $header }}@endif</div>

    <table>
        <thead>
            <tr>
                @foreach ($report->columns as $column)
                    <th class="{{ $column->isNumeric() ? 'r' : '' }}">{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report->rows as $row)
                <tr class="{{ $row['_style'] ?? '' }}">
                    @foreach ($report->columns as $column)
                        <td class="{{ $column->isNumeric() ? 'r' : '' }} {{ in_array($column->key, $row['_warn'] ?? [], true) ? 'warn' : '' }}">{{ $column->format($row[$column->key] ?? null) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($report->notes)
        <div class="notes muted">
            @foreach ($report->notes as $note)<div>{{ $note }}</div>@endforeach
        </div>
    @endif
</body>
</html>
