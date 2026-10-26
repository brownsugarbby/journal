<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A cell of one of H D's own pay columns. Info columns may hold text.
 */
#[Guarded(['id'])]
class PayItemValue extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payroll')
            ->logOnly(['value', 'text'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<PayItem, $this>
     */
    public function payItem(): BelongsTo
    {
        return $this->belongsTo(PayItem::class);
    }

    /**
     * @return BelongsTo<PayColumn, $this>
     */
    public function payColumn(): BelongsTo
    {
        return $this->belongsTo(PayColumn::class);
    }
}
