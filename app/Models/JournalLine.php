<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Signed amount a journal puts on one account or category (positive = debit,
 * i.e. money in for a company account). Written only by JournalService.
 */
#[Guarded(['id'])]
class JournalLine extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'idr_amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function ledgerable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
