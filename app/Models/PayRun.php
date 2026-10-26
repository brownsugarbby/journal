<?php

namespace App\Models;

use App\Enums\PayRunStatus;
use App\Enums\PayType;
use App\Policies\PayRunPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One month's Gaji or UM sheet. Draft = editable grid; paying it posts one
 * journal per currency and locks it.
 */
#[Guarded(['id'])]
#[UsePolicy(PayRunPolicy::class)]
class PayRun extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'status' => PayRunStatus::class,
            'pay_date' => 'date',
            'kurs' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payroll')
            ->logOnly(['pay_type', 'year', 'month', 'pay_date', 'status', 'kurs', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<PayItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayItem::class);
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->status === PayRunStatus::Paid;
    }

    public function monthLabel(): string
    {
        return now()->setDate($this->year, $this->month, 1)->translatedFormat('F Y');
    }

    /**
     * "Gaji Oktober 2026", "UM Oktober 2026".
     */
    public function label(): string
    {
        return $this->pay_type->getLabel().' '.$this->monthLabel();
    }
}
