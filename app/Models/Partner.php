<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Owner / partner equity account (Ko TM, Ko HD...).
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Partner extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'profit_share_percentage' => 'decimal:2',
            'opening_balance' => 'decimal:2',
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
     * @return MorphMany<JournalLine, $this>
     */
    public function lines(): MorphMany
    {
        return $this->morphMany(JournalLine::class, 'ledgerable');
    }

    /**
     * Partner's money in the business: opening + capital − prive. Lines are
     * credits (capital = −), so the sign is flipped.
     */
    public function balance(): string
    {
        return bcsub((string) $this->opening_balance, (string) ($this->lines()->sum('idr_amount') ?: '0'), 2);
    }
}
