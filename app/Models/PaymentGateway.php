<?php

namespace App\Models;

use App\Enums\FeePaidBy;
use App\Enums\FeeType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Observers\PaymentGatewayObserver;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment gateway (PGA HOKI, ...). Adding a new PGA is one row: its fee
 * history and P&L fee categories are created by the observer.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
#[ObservedBy(PaymentGatewayObserver::class)]
class PaymentGateway extends Model
{
    use LogsMasterDataActivity;

    public const FEE_FIELDS = ['dp_fee_type', 'dp_fee_value', 'wd_fee_type', 'wd_fee_value', 'wd_fee_paid_by'];

    /**
     * Mirrors the column defaults so the observer can snapshot fees right after create.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dp_fee_type' => 'flat',
        'dp_fee_value' => 0,
        'wd_fee_type' => 'flat',
        'wd_fee_value' => 0,
        'wd_fee_paid_by' => 'company',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'dp_fee_type' => FeeType::class,
            'dp_fee_value' => 'decimal:4',
            'wd_fee_type' => FeeType::class,
            'wd_fee_value' => 'decimal:4',
            'wd_fee_paid_by' => FeePaidBy::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<PaymentGatewayFeeHistory, $this>
     */
    public function feeHistories(): HasMany
    {
        return $this->hasMany(PaymentGatewayFeeHistory::class)->latest('effective_from');
    }

    /**
     * @return HasMany<CompanyAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(CompanyAccount::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function feeSourceKey(string $direction): string
    {
        return "pga:{$this->id}:{$direction}_fee";
    }
}
