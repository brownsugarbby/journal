<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A calendar month of books. Opened automatically by the first entry dated
 * in it; closing (Phase 5) makes it read-only.
 */
#[Guarded(['id'])]
class Period extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public static function forDate(CarbonInterface $date): self
    {
        return static::query()->firstOrCreate(
            ['year' => $date->year, 'month' => $date->month],
            ['status' => PeriodStatus::Open],
        );
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function label(): string
    {
        return now()->setDate($this->year, $this->month, 1)->translatedFormat('F Y');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Closing snapshot (tutup buku), one row per account.
     *
     * @return HasMany<PeriodBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(PeriodBalance::class);
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }
}
