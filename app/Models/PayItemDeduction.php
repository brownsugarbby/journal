<?php

namespace App\Models;

use App\Enums\PayDeductionKind;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reason behind a pay row's POTONG; the lines add up to PayItem::potong.
 */
#[Guarded(['id'])]
class PayItemDeduction extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => PayDeductionKind::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PayItem, $this>
     */
    public function payItem(): BelongsTo
    {
        return $this->belongsTo(PayItem::class);
    }
}
