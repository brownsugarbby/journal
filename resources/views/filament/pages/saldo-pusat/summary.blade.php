@php
    use App\Filament\Support\Fields;

    // Inline styles on Filament's color variables: no custom Tailwind theme needed.
    $card = 'border: 1px solid var(--gray-200); border-radius: .75rem; padding: 1rem; background: var(--color-white, #fff)';
    $muted = 'color: var(--gray-500)';
    $row = 'display: flex; justify-content: space-between; gap: 1rem; padding: .25rem 0; font-variant-numeric: tabular-nums';
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; font-size: .875rem">
    <div style="{{ $card }}">
        <div style="font-weight: 700; margin-bottom: .5rem">{{ __('Total saldo pusat') }}</div>
        @foreach ($currencyTotals as $total)
            <div style="{{ $row }}">
                <span>{{ $total['code'] }}</span>
                <span>
                    {{ number_format((float) $total['balance'], $total['decimals'], ',', '.') }}
                    @if (! $total['is_base'])
                        <span style="{{ $muted }}">· Rp {{ Fields::rupiah($total['idr']) }}</span>
                    @endif
                </span>
            </div>
        @endforeach
        <div style="{{ $row }}; border-top: 1px solid var(--gray-200); margin-top: .25rem; padding-top: .5rem; font-weight: 700">
            <span>{{ __('Total in Rp') }}</span>
            <span>{{ Fields::rupiah($grandTotalIdr) }}</span>
        </div>
    </div>

    <div style="{{ $card }}">
        <div style="font-weight: 700; margin-bottom: .5rem">Pengeluaran kantor · {{ $monthLabel }}</div>
        <div style="{{ $row }}">
            <span>Total</span>
            <span style="font-weight: 700">{{ Fields::rupiah($expenseTotal) }}</span>
        </div>
    </div>

    <div style="{{ $card }}">
        <div style="font-weight: 700; margin-bottom: .5rem">Pinjaman</div>
        @forelse ($loans as $loan)
            <div style="{{ $row }}">
                <span>{{ $loan['name'] }}</span>
                <span style="{{ bccomp($loan['balance'], '0', 2) < 0 ? 'color: var(--danger-600)' : '' }}">
                    {{ bccomp($loan['balance'], '0', 2) < 0 ? __('we owe :amount', ['amount' => Fields::rupiah(ltrim($loan['balance'], '-'))]) : __('owes us :amount', ['amount' => Fields::rupiah($loan['balance'])]) }}
                </span>
            </div>
        @empty
            <div style="{{ $muted }}">{{ __('Nothing outstanding') }}</div>
        @endforelse
    </div>

    <div style="{{ $card }}">
        <div style="font-weight: 700; margin-bottom: .5rem">{{ __('Partners') }}</div>
        @forelse ($partners as $partner)
            <div style="{{ $row }}">
                <span>{{ $partner['name'] }}</span>
                <span>{{ Fields::rupiah($partner['balance']) }}</span>
            </div>
        @empty
            <div style="{{ $muted }}">{{ __('No partners') }}</div>
        @endforelse
    </div>
</div>
