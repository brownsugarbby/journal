<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salary and UM can each be in their own currency (e.g. salary IDR, UM THB).
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Employee extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'salary_amount' => 'decimal:2',
            'um_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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
    public function salaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'salary_currency_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function umCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'um_currency_id');
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * LAMA KERJA, e.g. "2 th 3 bln".
     */
    public function lengthOfService(): ?string
    {
        if (! $this->join_date) {
            return null;
        }

        $diff = $this->join_date->diff(now());

        return trim(($diff->y ? "{$diff->y} th " : '').("{$diff->m} bln"));
    }
}
