<?php

namespace App\Models\Concerns;

use App\Models\CompanyAccount;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loan counterparty or partner whose balance is kept in its own currency.
 * Rupiah (or none set): lines count by their IDR value, as before. Dollars /
 * baht: lines count in that currency, and the rupiah value is the balance at
 * today's average kurs of the company's accounts in that currency (so a USD
 * loan stays "$100 owed" whatever the rate does).
 */
trait HoldsCurrency
{
    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function isForeign(): bool
    {
        return $this->currency !== null && ! $this->currency->is_base;
    }

    public function currencyCode(): string
    {
        return $this->currency?->code ?? Currency::query()->where('is_base', true)->value('code') ?? 'IDR';
    }

    /**
     * Σ of this ledger's lines in its own currency.
     */
    protected function movements(): string
    {
        return (string) ($this->lines()->sum($this->isForeign() ? 'amount' : 'idr_amount') ?: '0');
    }

    /**
     * Rupiah value of a balance in this currency; null when no kurs is known yet.
     */
    public function idrValue(string $balance): ?string
    {
        if (! $this->isForeign()) {
            return $balance;
        }

        $kurs = CompanyAccount::query()
            ->where('currency_id', $this->currency_id)
            ->where('is_active', true)
            ->get()
            ->map(fn (CompanyAccount $account): ?string => $account->averageKurs())
            ->filter()
            ->first();

        return $kurs === null ? null : bcmul($balance, $kurs, 2);
    }
}
