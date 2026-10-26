<?php

namespace App\Models;

use App\Enums\AccountKind;
use App\Enums\AccountPurpose;
use App\Enums\AccountTier;
use App\Enums\FeeType;
use App\Enums\JournalType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A place company money sits: a bank account (points to a Bank), a gateway
 * account (points to a PGA) or cash. Operational accounts take member DP/WD
 * (max 1jt); pusat accounts hold central money (max 50jt).
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class CompanyAccount extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'kind' => AccountKind::class,
            'tier' => AccountTier::class,
            'purpose' => AccountPurpose::class,
            'opening_balance' => 'decimal:2',
            'opening_kurs' => 'decimal:6',
            'opening_date' => 'date',
            'max_balance' => 'decimal:2',
            'dp_fee_type' => FeeType::class,
            'dp_fee_value' => 'decimal:4',
            'wd_fee_type' => FeeType::class,
            'wd_fee_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Keep the provider columns consistent with the kind, whatever the form sent.
        static::saving(function (CompanyAccount $account): void {
            if ($account->kind !== AccountKind::Bank) {
                $account->bank_id = null;
            }

            if ($account->kind !== AccountKind::Gateway) {
                $account->payment_gateway_id = null;
                $account->dp_fee_type = $account->dp_fee_value = null;
                $account->wd_fee_type = $account->wd_fee_value = null;
            }

            if ($account->tier === AccountTier::Pusat) {
                $account->brand_id = null;
            }
        });
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<PaymentGateway, $this>
     */
    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Admins assigned to post on this account.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return MorphMany<JournalLine, $this>
     */
    public function lines(): MorphMany
    {
        return $this->morphMany(JournalLine::class, 'ledgerable');
    }

    /**
     * Book balance: opening balance + every line posted on this account.
     */
    public function balance(): string
    {
        return bcadd((string) $this->opening_balance, (string) ($this->lines()->sum('amount') ?: '0'), 2);
    }

    public function isBaseCurrency(): bool
    {
        return (bool) $this->currency?->is_base;
    }

    /**
     * Weighted-average kurs (IDR per 1 unit) of the money in this account:
     * total IDR value / total units, opening balance included at its kurs.
     * Purchases move the average; spending leaves it unchanged. Null when a
     * foreign account has never been bought into and has no opening kurs.
     */
    public function averageKurs(): ?string
    {
        if ($this->isBaseCurrency()) {
            return '1';
        }

        $totals = $this->lines()->selectRaw('coalesce(sum(amount), 0) as units, coalesce(sum(idr_amount), 0) as idr')->first();
        $openingIdr = bcmul((string) $this->opening_balance, (string) ($this->opening_kurs ?? 0), 2);
        $units = bcadd((string) $this->opening_balance, (string) $totals->units, 2);
        $idr = bcadd($openingIdr, (string) $totals->idr, 2);

        if (bccomp($units, '0', 2) === 1 && bccomp($idr, '0', 2) === 1) {
            return bcdiv($idr, $units, 6);
        }

        // Empty account: fall back to the last purchase, then the opening kurs.
        return $this->lastPurchaseKurs() ?? ($this->opening_kurs !== null ? (string) $this->opening_kurs : null);
    }

    public function lastPurchaseKurs(): ?string
    {
        $kurs = Journal::query()
            ->where('target_account_id', $this->id)
            ->where('type', JournalType::CurrencyPurchase)
            ->whereNull('voided_at')
            ->latest('id')
            ->value('kurs');

        return $kurs !== null ? (string) $kurs : null;
    }

    /**
     * IDR value of the balance at the average kurs (for Saldo Pusat).
     */
    public function idrValue(?string $balance = null): ?string
    {
        $kurs = $this->averageKurs();

        return $kurs === null ? null : bcmul($balance ?? $this->balance(), $kurs, 2);
    }

    public function isOverMax(?string $balance = null): bool
    {
        return $this->max_balance !== null
            && bccomp($balance ?? $this->balance(), (string) $this->max_balance, 2) === 1;
    }

    /**
     * @return HasMany<BalanceCheck, $this>
     */
    public function balanceChecks(): HasMany
    {
        return $this->hasMany(BalanceCheck::class)->latest('id');
    }

    public function providerName(): string
    {
        return match ($this->kind) {
            AccountKind::Bank => $this->bank?->name ?? '-',
            AccountKind::Gateway => 'PGA '.($this->paymentGateway?->name ?? '-'),
            AccountKind::Cash => 'Cash',
        };
    }

    /**
     * Fee that pre-fills POTONGAN: the account override, else the PGA default.
     *
     * @return array{type: FeeType, value: string}|null
     */
    public function feeFor(string $direction): ?array
    {
        if ($this->kind !== AccountKind::Gateway || ! $this->paymentGateway) {
            return null;
        }

        $type = $this->{"{$direction}_fee_type"} ?? $this->paymentGateway->{"{$direction}_fee_type"};
        $value = $this->{"{$direction}_fee_value"} ?? $this->paymentGateway->{"{$direction}_fee_value"};

        return ['type' => $type, 'value' => $value];
    }
}
