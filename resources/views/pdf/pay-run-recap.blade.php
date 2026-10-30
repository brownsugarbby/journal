<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $run->label() }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #047857; color: #fff; padding: 5px 6px; text-align: left; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .r { text-align: right; white-space: nowrap; }
        .b { font-weight: bold; }
        .totals { width: 60%; margin-left: auto; }
        .totals td { border-bottom: 1px solid #e5e7eb; }
        .grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #111; border-bottom: none; }
    </style>
</head>
<body>
    <h1>{{ $company }} · {{ strtoupper($run->label()) }}</h1>
    <div class="muted">
        {{ __('Pay date :date', ['date' => $run->pay_date->format('d/m/Y')]) }} · {{ $run->status->getLabel() }}
        @if ($run->isPaid()) {{ __('by :user', ['user' => $run->paidBy?->username]) }} @endif
        · {{ __('printed :date', ['date' => now()->format('d/m/Y H:i')]) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>PT</th><th>Jabatan</th><th>Nama</th><th>Lama kerja</th>
                @foreach ($columns as $column)<th class="r">{{ $column->name }}</th>@endforeach
                <th class="r">{{ $run->pay_type->getLabel() }}</th><th class="r">Potong</th><th class="r">Pinjaman</th><th class="r">Sisa</th>
                <th>Cur</th><th>Bank</th><th>No rek</th><th>Nama rek</th><th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                @php($fmt = fn ($v) => number_format((float) $v, $item->currency->decimals, ',', '.'))
                <tr>
                    <td>{{ $item->employee->company?->short_code }}</td>
                    <td>{{ $item->employee->position }}</td>
                    <td class="b">{{ $item->employee->name }}</td>
                    <td>{{ $item->employee->lengthOfService() }}</td>
                    @foreach ($columns as $column)
                        @php($value = $item->valueFor($column))
                        <td class="r">{{ $value?->text ?? ($value?->value !== null ? $fmt($value->value) : '') }}</td>
                    @endforeach
                    <td class="r">{{ $fmt($item->gross) }}</td>
                    <td class="r">{{ $fmt($item->potong) }}</td>
                    <td class="r">{{ $fmt($item->loan_repayment) }}</td>
                    <td class="r b">{{ $fmt($item->sisa()) }}</td>
                    <td>{{ $item->currency->code }}</td>
                    <td>{{ $item->employee->bank?->short_name }}</td>
                    <td>{{ $item->employee->account_number }}</td>
                    <td>{{ $item->employee->account_name }}</td>
                    <td>{{ $item->note }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach ($totals['currencies'] as $row)
            @php($fmt = fn ($v) => number_format((float) $v, $row['decimals'], ',', '.'))
            <tr>
                <td class="b">{{ $row['code'] }} ({{ $row['count'] }} org)</td>
                <td class="r">{{ $fmt($row['sisa']) }}</td>
                <td class="r">
                    @unless ($row['is_base'])
                        × {{ $row['kurs'] !== null ? rtrim(rtrim(number_format((float) $row['kurs'], 4, ',', '.'), '0'), ',') : '?' }}
                    @endunless
                </td>
                <td class="r">Rp {{ $row['idr'] !== null ? number_format((float) $row['idr'], 0, ',', '.') : '-' }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td colspan="3">TOTAL ALL</td>
            <td class="r">Rp {{ $totals['total_all'] !== null ? number_format((float) $totals['total_all'], 0, ',', '.') : '-' }}</td>
        </tr>
    </table>
</body>
</html>
