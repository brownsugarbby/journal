@php
    use App\Filament\Support\Fields;

    // Inline styles on Filament's color variables: no custom Tailwind theme needed.
    $card = 'border: 1px solid var(--gray-200); border-radius: .75rem; padding: .75rem 1rem';
    $muted = 'color: var(--gray-500)';
    $cell = 'padding: .25rem .75rem; text-align: right; font-variant-numeric: tabular-nums';
@endphp

<div style="{{ $card }}; overflow-x: auto; font-size: .875rem">
    <table style="width: 100%; border-collapse: collapse">
        <thead>
            <tr style="{{ $muted }}">
                <th style="padding: .25rem .75rem; text-align: left">Currency</th>
                <th style="{{ $cell }}">Org</th>
                <th style="{{ $cell }}">{{ $run->pay_type->getLabel() }} full</th>
                <th style="{{ $cell }}">Potong</th>
                <th style="{{ $cell }}">Pinjaman</th>
                <th style="{{ $cell }}">Sisa</th>
                <th style="{{ $cell }}">Kurs</th>
                <th style="{{ $cell }}">Total Rp</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($totals['currencies'] as $row)
                @php($fmt = fn ($v) => number_format((float) $v, $row['decimals'], ',', '.'))
                <tr style="border-top: 1px solid var(--gray-100)">
                    <td style="padding: .25rem .75rem; font-weight: 700">{{ $row['code'] }}</td>
                    <td style="{{ $cell }}">{{ $row['count'] }}</td>
                    <td style="{{ $cell }}">{{ $fmt($row['gross']) }}</td>
                    <td style="{{ $cell }}">{{ $fmt($row['potong']) }}</td>
                    <td style="{{ $cell }}">{{ $fmt($row['loan_repayment']) }}</td>
                    <td style="{{ $cell }}; font-weight: 700">{{ $fmt($row['sisa']) }}</td>
                    <td style="{{ $cell }}">
                        @if ($row['is_base'])
                            <span style="{{ $muted }}">-</span>
                        @elseif ($row['kurs'] === null)
                            <span style="color: var(--danger-600)">buy {{ $row['code'] }} first</span>
                        @else
                            {{ rtrim(rtrim(number_format((float) $row['kurs'], 4, ',', '.'), '0'), ',') }}
                            @unless ($run->isPaid())<span style="{{ $muted }}">(today)</span>@endunless
                        @endif
                    </td>
                    <td style="{{ $cell }}">{{ $row['idr'] !== null ? Fields::rupiah($row['idr']) : '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="padding: .5rem .75rem; {{ $muted }}">No rows yet.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid var(--gray-300)">
                <td colspan="7" style="{{ $cell }}; font-weight: 700">TOTAL ALL</td>
                <td style="{{ $cell }}; font-weight: 700; font-size: 1rem">{{ $totals['total_all'] !== null ? Fields::rupiah($totals['total_all']) : '-' }}</td>
            </tr>
        </tfoot>
    </table>
</div>
