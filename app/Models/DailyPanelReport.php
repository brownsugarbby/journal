<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The game panel's figures for one brand and day (coin side), typed in by
 * staff and compared with the journal (money side) in the Daily Recap.
 */
#[Guarded(['id'])]
class DailyPanelReport extends Model
{
    use LogsActivity;

    public const FIGURES = ['new_member', 'winlose', 'turnover', 'from_dp', 'form_bonus', 'from_wd', 'from_wd_manual'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'new_member' => 'integer',
            'winlose' => 'decimal:2',
            'turnover' => 'decimal:2',
            'from_dp' => 'decimal:2',
            'form_bonus' => 'decimal:2',
            'from_wd' => 'decimal:2',
            'from_wd_manual' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DailyPanelReport $report): void {
            if ($report->isDirty(self::FIGURES)) {
                $report->updated_by = auth()->id();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('panel_report')
            ->logOnly(self::FIGURES)
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * FORM BERSIH (DP side) = FROM DP − FORM BONUS.
     */
    public function formBersihDp(): ?string
    {
        return $this->from_dp === null ? null : bcsub((string) $this->from_dp, (string) ($this->form_bonus ?? 0), 2);
    }

    /**
     * FORM BERSIH (WD side) = FROM WD − FROM WD MANUAL.
     */
    public function formBersihWd(): ?string
    {
        return $this->from_wd === null ? null : bcsub((string) $this->from_wd, (string) ($this->from_wd_manual ?? 0), 2);
    }
}
