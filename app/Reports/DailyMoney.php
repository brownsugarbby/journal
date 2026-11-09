<?php

namespace App\Reports;

use App\Enums\AccountKind;
use App\Enums\JournalType;
use App\Models\Journal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The money side of the REPORT TUTUP BUKU sheet, per day, from the jurnal:
 *
 *   TOTAL DP ALL   = TOTAL DP + TOTAL DP PGA
 *   TOTAL WD ALL   = TOTAL WD − TOTAL WD MANUAL
 *   TOTAL IN/OUT   = TOTAL DP ALL − TOTAL WD ALL
 *   BONUS MANUAL   = bonus + cashback
 */
class DailyMoney
{
    public const KEYS = ['dp', 'dp_pga', 'dp_all', 'wd', 'wd_manual', 'wd_all', 'in_out', 'bonus_manual'];

    /**
     * The columns above for every day with entries, keyed by Y-m-d. Voided
     * entries and their reversals are left out.
     *
     * @return Collection<string, array<string, string>>
     */
    public static function byDay(string $from, string $to, ?int $brandId = null): Collection
    {
        $gateway = AccountKind::Gateway->value;

        return Journal::query()
            ->leftJoin('company_accounts as ca', 'ca.id', '=', 'journals.company_account_id')
            ->whereNull('journals.voided_at')
            ->whereNull('journals.reversal_of_id')
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->when($brandId, fn (Builder $query) => $query->where('journals.brand_id', $brandId))
            ->groupBy(DB::raw('date(journals.entry_date)'))
            ->selectRaw('date(journals.entry_date) as day')
            ->selectRaw("sum(case when journals.type = ? and ca.kind <> '{$gateway}' then journals.amount else 0 end) as dp_bank", [JournalType::Deposit->value])
            ->selectRaw("sum(case when journals.type = ? and ca.kind = '{$gateway}' then journals.amount else 0 end) as dp_pga", [JournalType::Deposit->value])
            ->selectRaw('sum(case when journals.type = ? then journals.amount else 0 end) as wd', [JournalType::Withdraw->value])
            ->selectRaw('sum(case when journals.type = ? and journals.is_manual = 1 then journals.amount else 0 end) as wd_manual', [JournalType::Withdraw->value])
            ->selectRaw('sum(case when journals.type in (?, ?) then journals.amount else 0 end) as bonus', [JournalType::Bonus->value, JournalType::Cashback->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $day): array => [$day->day => self::row($day)]);
    }

    /**
     * A day without entries: every column 0.
     *
     * @return array<string, string>
     */
    public static function empty(): array
    {
        return self::row(null);
    }

    /**
     * @return array<string, string>
     */
    private static function row(?object $sums): array
    {
        // SQLite sums come back as int / float, MySQL as "500000.00": same shape for both.
        $dp = bcadd((string) ($sums->dp_bank ?? 0), '0', 2);
        $dpPga = bcadd((string) ($sums->dp_pga ?? 0), '0', 2);
        $wd = bcadd((string) ($sums->wd ?? 0), '0', 2);
        $wdManual = bcadd((string) ($sums->wd_manual ?? 0), '0', 2);
        $dpAll = bcadd($dp, $dpPga, 2);
        $wdAll = bcsub($wd, $wdManual, 2);

        return [
            'dp' => $dp,
            'dp_pga' => $dpPga,
            'dp_all' => $dpAll,
            'wd' => $wd,
            'wd_manual' => $wdManual,
            'wd_all' => $wdAll,
            'in_out' => bcsub($dpAll, $wdAll, 2),
            'bonus_manual' => bcadd((string) ($sums->bonus ?? 0), '0', 2),
        ];
    }
}
