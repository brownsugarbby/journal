@php
    use App\Filament\Support\Fields;

    // Inline styles on Filament's color variables: no custom Tailwind theme needed.
    $muted = 'color: var(--gray-500)';
    $danger = 'color: var(--danger-600); font-weight: 600';
    $success = 'color: var(--success-600)';
@endphp

<div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem 2rem; font-size: .875rem; font-variant-numeric: tabular-nums">
    <div>
        <span style="{{ $muted }}">SALDO</span>
        <span style="margin-inline-start: .25rem; font-size: 1.125rem; font-weight: 700; {{ $overMax ? $danger : '' }}">{{ Fields::rupiah($balance) }}</span>
    </div>

    <div>
        <span style="{{ $muted }}">{{ __('Max') }}</span>
        <span style="margin-inline-start: .25rem">{{ $account->max_balance !== null ? Fields::rupiah($account->max_balance) : __('No limit') }}</span>
    </div>

    @if (! empty($kurs))
        <div>
            <span style="{{ $muted }}">{{ __('Avg kurs') }}</span>
            <span style="margin-inline-start: .25rem">{{ rtrim(rtrim(number_format((float) $kurs, 4, ',', '.'), '0'), ',') }}</span>
        </div>

        <div>
            <span style="{{ $muted }}">Nilai Rp</span>
            <span style="margin-inline-start: .25rem">{{ Fields::rupiah($idrValue) }}</span>
        </div>
    @endif

    <div>
        <span style="{{ $muted }}">{{ __('Provider') }}</span>
        <span style="margin-inline-start: .25rem">{{ $account->providerName() }}</span>
    </div>

    <div>
        <span style="{{ $muted }}">{{ __('Cek saldo') }}</span>
        @if ($lastCheck)
            <span style="margin-inline-start: .25rem; {{ $lastCheck->isMatching() ? $success : $danger }}">
                {{ $lastCheck->isMatching() ? __('Match') : __('Selisih :amount', ['amount' => Fields::rupiah($lastCheck->difference)]) }}
            </span>
            <span style="{{ $muted }}">· {{ $lastCheck->created_at->translatedFormat('d M H:i') }} · {{ $lastCheck->createdBy->username }}</span>
        @else
            <span style="margin-inline-start: .25rem; {{ $muted }}">{{ __('Never') }}</span>
        @endif
    </div>

    @if ($overMax)
        <div style="{{ $danger }}">{{ __('Over max balance: transfer to pusat.') }}</div>
    @endif
</div>
