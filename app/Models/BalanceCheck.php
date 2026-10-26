<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * "Cek Saldo": the real balance read from mBanking / the PGA dashboard,
 * compared with the book balance at that moment.
 */
#[Guarded(['id'])]
class BalanceCheck extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'actual_balance' => 'decimal:2',
            'book_balance' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('journal')->logAll()->logExcept(['created_at', 'updated_at']);
    }

    /**
     * @return BelongsTo<CompanyAccount, $this>
     */
    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isMatching(): bool
    {
        return bccomp((string) $this->difference, '0', 2) === 0;
    }
}
