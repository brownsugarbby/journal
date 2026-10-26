<?php

namespace App\Models;

use App\Enums\PayColumnKind;
use App\Enums\PayType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra pay-run columns H D defines themselves (e.g. the hidden sheet columns).
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class PayColumn extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'kind' => PayColumnKind::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Optional own P&L line for this column's amounts (e.g. Lembur Offday);
     * empty = booked with the run's Gaji / UM line.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
