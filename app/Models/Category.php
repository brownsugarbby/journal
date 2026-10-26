<?php

namespace App\Models;

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\PlGroup;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A P&L line (Hitung Bersih). Auto categories are filled by the system via `source_key`.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Category extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'pl_group' => PlGroup::class,
            'type' => CategoryType::class,
            'source' => CategorySource::class,
            'show_headcount' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PaymentGateway, $this>
     */
    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
