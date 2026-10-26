<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's closing balance in a closed month.
 */
#[Guarded(['id'])]
class PeriodBalance extends Model
{
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'kurs' => 'decimal:6',
            'idr_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CompanyAccount, $this>
     */
    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class);
    }
}
