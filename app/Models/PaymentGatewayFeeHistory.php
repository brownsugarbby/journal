<?php

namespace App\Models;

use App\Enums\FeePaidBy;
use App\Enums\FeeType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a PGA's fees from `effective_from` on, so old entries keep their fee.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class PaymentGatewayFeeHistory extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'dp_fee_type' => FeeType::class,
            'dp_fee_value' => 'decimal:4',
            'wd_fee_type' => FeeType::class,
            'wd_fee_value' => 'decimal:4',
            'wd_fee_paid_by' => FeePaidBy::class,
        ];
    }

    /**
     * @return BelongsTo<PaymentGateway, $this>
     */
    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
