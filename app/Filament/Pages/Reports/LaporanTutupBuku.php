<?php

namespace App\Filament\Pages\Reports;

use App\Enums\AccountKind;
use App\Enums\JournalType;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\DailyPanelReport;
use App\Models\Journal;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Carbon\CarbonPeriod;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * REPORT TUTUP BUKU: one row per date of the month, same columns and
 * formulas as the sheet. Money columns come from the journal, panel
 * columns from the Daily Panel Report; the two SELISIH columns show where
 * money and coin do not match.
 *
 *   TOTAL DP ALL   = TOTAL DP + TOTAL DP PGA
 *   TOTAL WD ALL   = TOTAL WD − TOTAL WD MANUAL
 *   TOTAL IN/OUT   = TOTAL DP ALL − TOTAL WD ALL
 *   FORM BERSIH    = FROM DP − FORM BONUS        (DP side)
 *   FORM BERSIH    = FROM WD − FROM WD MANUAL    (WD side)
 */
class LaporanTutupBuku extends Page implements HasTable
{
    use IsReportPage;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Laporan Tutup Buku';

    protected static ?string $title = 'Laporan Tutup Buku';

    protected static ?string $slug = 'laporan/tutup-buku';

    #[Url]
    public ?string $month = null;

    #[Url]
    public ?string $brand = null;

    protected function filterFields(): array
    {
        return [$this->monthField(), $this->brandField()];
    }

    protected function buildReport(): ReportTable
    {
        $start = $this->monthStart();
        $end = $start->isSameMonth(today()) ? today() : $start->copy()->endOfMonth();

        $money = $this->moneyByDay($start->toDateString(), $start->copy()->endOfMonth()->toDateString());
        $panel = $this->panelByDay($start->toDateString(), $start->copy()->endOfMonth()->toDateString());

        $rows = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $d = $date->toDateString();
            $m = $money->get($d);
            $p = $panel->get($d);

            $dp = (string) ($m->dp_bank ?? 0);
            $dpPga = (string) ($m->dp_pga ?? 0);
            $wd = (string) ($m->wd ?? 0);
            $wdManual = (string) ($m->wd_manual ?? 0);
            $dpAll = bcadd($dp, $dpPga, 2);
            $wdAll = bcsub($wd, $wdManual, 2);
            $formBersihDp = $p && $p->from_dp !== null ? bcsub((string) $p->from_dp, (string) ($p->form_bonus ?? 0), 2) : null;
            $formBersihWd = $p && $p->from_wd !== null ? bcsub((string) $p->from_wd, (string) ($p->from_wd_manual ?? 0), 2) : null;

            $row = [
                'tanggal' => $date->format('d-m-Y'),
                'new_member' => $p->new_member ?? null,
                'dp' => $dp,
                'dp_pga' => $dpPga,
                'dp_all' => $dpAll,
                'wd' => $wd,
                'wd_manual' => $wdManual,
                'wd_all' => $wdAll,
                'in_out' => bcsub($dpAll, $wdAll, 2),
                'winlose' => $p->winlose ?? null,
                'turnover' => $p->turnover ?? null,
                'from_dp' => $p->from_dp ?? null,
                'form_bonus' => $p->form_bonus ?? null,
                'form_bersih_dp' => $formBersihDp,
                'from_wd' => $p->from_wd ?? null,
                'from_wd_manual' => $p->from_wd_manual ?? null,
                'form_bersih_wd' => $formBersihWd,
                'bonus_manual' => (string) ($m->bonus ?? 0),
                'selisih_dp' => $p && $p->from_dp !== null ? bcsub($dpAll, (string) $p->from_dp, 2) : null,
                'selisih_wd' => $formBersihWd !== null ? bcsub($wdAll, $formBersihWd, 2) : null,
            ];

            $row['_warn'] = array_values(array_filter(['selisih_dp', 'selisih_wd'], fn (string $key): bool => $row[$key] !== null && bccomp($row[$key], '0', 2) !== 0));
            $rows[] = $row;
        }

        $sumKeys = ['new_member', 'dp', 'dp_pga', 'dp_all', 'wd', 'wd_manual', 'wd_all', 'in_out', 'winlose', 'turnover', 'from_dp', 'form_bonus', 'form_bersih_dp', 'from_wd', 'from_wd_manual', 'form_bersih_wd', 'bonus_manual', 'selisih_dp', 'selisih_wd'];
        $total = ReportTable::totalRow($rows, $sumKeys, 'tanggal');
        $total['_warn'] = array_values(array_filter(['selisih_dp', 'selisih_wd'], fn (string $key): bool => bccomp((string) $total[$key], '0', 2) !== 0));
        $rows[] = $total;

        return new ReportTable(
            title: 'Laporan Tutup Buku',
            subtitle: $start->translatedFormat('F Y').' · '.$this->brandLabel(),
            columns: [
                ReportColumn::text('tanggal', 'Tanggal'),
                ReportColumn::number('new_member', 'New member'),
                ReportColumn::money('dp', 'Total DP'),
                ReportColumn::money('dp_pga', 'Total DP PGA'),
                ReportColumn::money('dp_all', 'Total DP all'),
                ReportColumn::money('wd', 'Total WD'),
                ReportColumn::money('wd_manual', 'Total WD manual'),
                ReportColumn::money('wd_all', 'Total WD all'),
                ReportColumn::money('in_out', 'Total in/out'),
                ReportColumn::money('winlose', 'Winlose harian'),
                ReportColumn::money('turnover', 'Turnover'),
                ReportColumn::money('from_dp', 'From DP'),
                ReportColumn::money('form_bonus', 'Form bonus'),
                ReportColumn::money('form_bersih_dp', 'Form bersih'),
                ReportColumn::money('from_wd', 'From WD'),
                ReportColumn::money('from_wd_manual', 'From WD manual'),
                ReportColumn::money('form_bersih_wd', 'Form bersih'),
                ReportColumn::money('bonus_manual', 'Bonus manual'),
                ReportColumn::money('selisih_dp', 'Selisih DP'),
                ReportColumn::money('selisih_wd', 'Selisih WD'),
            ],
            rows: $rows,
            notes: [
                __('Money columns from the jurnal (voided entries excluded). TOTAL DP = bank, TOTAL DP PGA = payment gateway (MASUK, before POTONGAN). Bonus manual = bonus + cashback entered in the jurnal.'),
                __('Panel columns from the Daily Panel Report. Selisih DP = DP all − From DP; Selisih WD = WD all − Form bersih (WD). Red = money and panel do not match.'),
            ],
            landscape: true,
        );
    }

    /**
     * Journal totals per day: DP on bank vs gateway accounts, WD, manual WD,
     * bonus + cashback. Voided entries and their reversals are left out.
     *
     * @return Collection<string, object>
     */
    protected function moneyByDay(string $from, string $to): Collection
    {
        $gateway = AccountKind::Gateway->value;

        return Journal::query()
            ->leftJoin('company_accounts as ca', 'ca.id', '=', 'journals.company_account_id')
            ->whereNull('journals.voided_at')
            ->whereNull('journals.reversal_of_id')
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->when($this->brandModel(), fn (Builder $query, $brand) => $query->where('journals.brand_id', $brand->id))
            ->groupBy(DB::raw('date(journals.entry_date)'))
            ->selectRaw('date(journals.entry_date) as day')
            ->selectRaw("sum(case when journals.type = ? and ca.kind <> '{$gateway}' then journals.amount else 0 end) as dp_bank", [JournalType::Deposit->value])
            ->selectRaw("sum(case when journals.type = ? and ca.kind = '{$gateway}' then journals.amount else 0 end) as dp_pga", [JournalType::Deposit->value])
            ->selectRaw('sum(case when journals.type = ? then journals.amount else 0 end) as wd', [JournalType::Withdraw->value])
            ->selectRaw('sum(case when journals.type = ? and journals.is_manual = 1 then journals.amount else 0 end) as wd_manual', [JournalType::Withdraw->value])
            ->selectRaw('sum(case when journals.type in (?, ?) then journals.amount else 0 end) as bonus', [JournalType::Bonus->value, JournalType::Cashback->value])
            ->get()
            ->keyBy('day');
    }

    /**
     * Panel figures per day, summed over brands when no brand is picked.
     *
     * @return Collection<string, object>
     */
    protected function panelByDay(string $from, string $to): Collection
    {
        $sums = collect(DailyPanelReport::FIGURES)
            ->map(fn (string $field): string => "sum({$field}) as {$field}")
            ->join(', ');

        return DailyPanelReport::query()
            ->whereDate('report_date', '>=', $from)
            ->whereDate('report_date', '<=', $to)
            ->when($this->brandModel(), fn (Builder $query, $brand) => $query->where('brand_id', $brand->id))
            ->groupBy(DB::raw('date(report_date)'))
            ->selectRaw("date(report_date) as day, {$sums}")
            ->toBase()
            ->get()
            ->keyBy('day');
    }
}
