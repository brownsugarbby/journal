<?php

namespace App\Models;

use App\Enums\LoanCounterpartyType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Who we lend to or borrow from (PINJAMAN).
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class LoanCounterparty extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'type' => LoanCounterpartyType::class,
            'interest_rate' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return MorphMany<JournalLine, $this>
     */
    public function lines(): MorphMany
    {
        return $this->morphMany(JournalLine::class, 'ledgerable');
    }

    /**
     * Outstanding in IDR (opening at go-live + movements): > 0 they owe us, < 0 we owe them.
     */
    public function balance(): string
    {
        return bcadd((string) $this->opening_balance, (string) ($this->lines()->sum('idr_amount') ?: '0'), 2);
    }
}
