<?php

namespace App\Models;

use App\Enums\PayColumnKind;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One employee's row in a pay run, in the employee's currency.
 *
 *   cost = GAJI / UM FULL + additions − deductions − POTONG   (what the month costs)
 *   SISA = cost − loan repayment                             (what is paid out)
 */
#[Guarded(['id'])]
class PayItem extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2',
            'potong' => 'decimal:2',
            'loan_repayment' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payroll')
            ->logOnly(['gross', 'potong', 'loan_repayment', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<PayItemValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(PayItemValue::class);
    }

    public function valueFor(PayColumn $column): ?PayItemValue
    {
        return $this->values->firstWhere('pay_column_id', $column->id);
    }

    /**
     * Sum of this row's custom column values of one kind.
     */
    public function columnTotal(PayColumnKind $kind): string
    {
        return $this->values
            ->filter(fn (PayItemValue $value): bool => $value->payColumn->kind === $kind)
            ->reduce(fn (string $sum, PayItemValue $value) => bcadd($sum, (string) ($value->value ?? 0), 2), '0');
    }

    public function cost(): string
    {
        return bcsub(
            bcadd((string) $this->gross, $this->columnTotal(PayColumnKind::Addition), 2),
            bcadd($this->columnTotal(PayColumnKind::Deduction), (string) $this->potong, 2),
            2,
        );
    }

    public function sisa(): string
    {
        return bcsub($this->cost(), (string) $this->loan_repayment, 2);
    }
}
