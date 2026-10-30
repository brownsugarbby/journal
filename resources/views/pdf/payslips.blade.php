<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $run->label() }}</title>
    <style>
        @page { margin: 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .slip { page-break-after: always; }
        .slip:last-child { page-break-after: auto; }
        .head { border-bottom: 2px solid #047857; padding-bottom: 8px; margin-bottom: 14px; }
        .head td { vertical-align: middle; }
        .company { font-size: 16px; font-weight: bold; }
        .title { font-size: 13px; font-weight: bold; color: #047857; text-align: right; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 3px 0; }
        .info td.k { width: 120px; color: #6b7280; }
        .amounts { margin-top: 14px; }
        .amounts td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .amounts td.v { text-align: right; white-space: nowrap; }
        .total td { font-size: 13px; font-weight: bold; border-top: 2px solid #111; border-bottom: none; }
        .sign { margin-top: 48px; }
        .sign td { width: 50%; text-align: center; padding-top: 48px; }
    </style>
</head>
<body>
@foreach ($items as $item)
    @php
        $d = $item->currency->decimals;
        $fmt = fn ($v) => $item->currency->code.' '.number_format((float) $v, $d, ',', '.');
        $employee = $item->employee;
    @endphp
    <div class="slip">
        <table class="head">
            <tr>
                <td>
                    @if ($logo)<img src="{{ $logo }}" style="height: 32px; vertical-align: middle; margin-right: 8px">@endif
                    <span class="company">{{ $company }}</span>
                    @if ($header)<div class="muted">{{ $header }}</div>@endif
                </td>
                <td class="title">SLIP {{ strtoupper($run->pay_type->getLabel()) }}<br><span class="muted">{{ $run->monthLabel() }}</span></td>
            </tr>
        </table>

        <table class="info">
            <tr><td class="k">Nama</td><td><strong>{{ $employee->name }}</strong></td><td class="k">PT</td><td>{{ $employee->company?->short_code }}</td></tr>
            <tr><td class="k">Jabatan</td><td>{{ $employee->position ?? '-' }}</td><td class="k">Lama kerja</td><td>{{ $employee->lengthOfService() ?? '-' }}</td></tr>
            <tr><td class="k">Bank</td><td>{{ $employee->bank?->name ?? '-' }} {{ $employee->account_number }}</td><td class="k">Nama rek</td><td>{{ $employee->account_name ?? '-' }}</td></tr>
            <tr><td class="k">Tanggal bayar</td><td>{{ $run->pay_date->format('d/m/Y') }}</td><td class="k">Status</td><td>{{ $run->status->getLabel() }}</td></tr>
        </table>

        <table class="amounts">
            <tr><td>{{ __(':type full', ['type' => $run->pay_type->getLabel()]) }}</td><td class="v">{{ $fmt($item->gross) }}</td></tr>
            @foreach ($columns as $column)
                @php($value = $item->valueFor($column))
                @continue(! $value || ($value->value === null && $value->text === null))
                <tr>
                    <td>{{ $column->name }}</td>
                    <td class="v">
                        @if ($value->text !== null)
                            {{ $value->text }}
                        @else
                            {{ $column->kind === $deductionKind ? '− ' : ($column->kind === $additionKind ? '+ ' : '') }}{{ $fmt($value->value) }}
                        @endif
                    </td>
                </tr>
            @endforeach
            @if ((float) $item->potong > 0)
                <tr><td>Potong</td><td class="v">− {{ $fmt($item->potong) }}</td></tr>
            @endif
            @if ((float) $item->loan_repayment > 0)
                <tr><td>Bayar pinjaman</td><td class="v">− {{ $fmt($item->loan_repayment) }}</td></tr>
            @endif
            <tr class="total"><td>SISA (dibayar)</td><td class="v">{{ $fmt($item->sisa()) }}</td></tr>
        </table>

        @if ($item->note)
            <p class="muted">Keterangan: {{ $item->note }}</p>
        @endif

        <table class="sign">
            <tr><td>Diterima,<br><br><br>{{ $employee->name }}</td><td>Dibayar,<br><br><br>{{ $company }}</td></tr>
        </table>
    </div>
@endforeach
</body>
</html>
