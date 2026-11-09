{{-- The sheets' first SALDO line: what the running Saldo column starts from.
     Set in Master Data → Rekening Perusahaan. --}}
<div class="fi-opening-row">
    <span>{{ __('Opening balance') }}</span>

    @if ($account->opening_date)
        <span class="fi-opening-row-date">{{ $account->opening_date->format('d/m/Y') }}</span>
    @endif

    <strong>{{ number_format((float) $account->opening_balance, $account->isBaseCurrency() ? 0 : 2, ',', '.') }}</strong>
</div>
