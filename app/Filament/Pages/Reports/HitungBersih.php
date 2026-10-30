<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PayRunStatus;
use App\Enums\PayType;
use App\Enums\PlGroup;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\Category;
use App\Models\JournalLine;
use App\Models\PayItem;
use App\Models\User;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HITUNG BERSIH TUTUP BUKU: the month's P&L in the sheet's layout:
 * Pendapatan, Biaya Tetap, Biaya Variable (every category line, in order),
 * then REKAPAN and LABA BERSIH = Pendapatan − Biaya Tetap − Biaya Variable.
 * Amounts are in Rp (THB / USD valued at their kurs). Superadmin only.
 */
class HitungBersih extends Page implements HasTable
{
    use IsReportPage;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Hitung Bersih (Profit)';

    protected static ?string $title = 'Hitung Bersih';

    protected static ?string $slug = 'laporan/hitung-bersih';

    #[Url]
    public ?string $month = null;

    #[Url]
    public ?string $brand = null;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->canViewProfit() ?? false;
    }

    protected function filterFields(): array
    {
        return [$this->monthField(), $this->brandField(__('All brands (consolidated)'))];
    }

    protected function buildReport(): ReportTable
    {
        $start = $this->monthStart();
        $sums = $this->categorySums($start->toDateString(), $start->copy()->endOfMonth()->toDateString());
        $headcounts = $this->headcounts($start->year, $start->month);

        $rows = [];
        $groupTotals = [];

        foreach ([PlGroup::Pendapatan, PlGroup::BiayaTetap, PlGroup::BiayaVariable, PlGroup::NotInPl] as $group) {
            $categories = Category::query()->where('pl_group', $group)->orderBy('sort_order')->get()
                ->filter(fn (Category $category): bool => $category->is_active || isset($sums[$category->id]));

            $lines = [];
            $groupTotal = '0';

            foreach ($categories as $category) {
                // Income lines are credits in the ledger: flip so revenue reads positive.
                $raw = (string) ($sums[$category->id] ?? '0');
                $amount = $group === PlGroup::Pendapatan ? bcmul($raw, '-1', 2) : $raw;
                $label = $category->name;

                if ($category->show_headcount && isset($headcounts[$category->source_key])) {
                    $label .= " ({$headcounts[$category->source_key]} Org)";
                }

                $lines[] = ['keterangan' => '   • '.$label, 'jumlah' => $amount] + ($group === PlGroup::NotInPl ? ['_style' => 'muted'] : []);
                $groupTotal = bcadd($groupTotal, $amount, 2);
            }

            $groupTotals[$group->value] = $groupTotal;
            $rows[] = ['keterangan' => $group === PlGroup::NotInPl ? __('Not in P&L (info)') : $group->getLabel(), 'jumlah' => $groupTotal, '_style' => 'section'];
            array_push($rows, ...$lines);
        }

        $profit = bcsub(
            $groupTotals[PlGroup::Pendapatan->value],
            bcadd($groupTotals[PlGroup::BiayaTetap->value], $groupTotals[PlGroup::BiayaVariable->value], 2),
            2,
        );

        array_push($rows,
            ['keterangan' => 'REKAPAN', 'jumlah' => null, '_style' => 'section'],
            ['keterangan' => 'Pendapatan Kotor', 'jumlah' => $groupTotals[PlGroup::Pendapatan->value]],
            ['keterangan' => 'Biaya Tetap', 'jumlah' => bcmul($groupTotals[PlGroup::BiayaTetap->value], '-1', 2)],
            ['keterangan' => 'Biaya Variable', 'jumlah' => bcmul($groupTotals[PlGroup::BiayaVariable->value], '-1', 2)],
            ['keterangan' => 'LABA BERSIH', 'jumlah' => $profit, '_style' => 'total'] + (bccomp($profit, '0', 2) < 0 ? ['_warn' => ['jumlah']] : []),
        );

        return new ReportTable(
            title: 'Hitung Bersih',
            subtitle: ($this->brandModel() ? substr($this->brandModel()->code, 0, 1).' ' : '').strtoupper($start->translatedFormat('F Y')).' · '.$this->brandLabel(),
            columns: [
                ReportColumn::text('keterangan', 'Keterangan'),
                ReportColumn::money('jumlah', 'Jumlah (Rp)'),
            ],
            rows: $rows,
            notes: array_filter([
                __('LABA BERSIH = Pendapatan Kotor − Biaya Tetap − Biaya Variable. "Not in P&L" lines (bonus, cashback, selisih kurs, penyesuaian) are shown for information and are not in the profit until H D confirms where they belong.'),
                $this->brandModel() ? __('Per brand: only entries tagged with this brand. Shared costs (no WEB / brand) appear in the consolidated view only.') : null,
            ]),
        );
    }

    /**
     * Σ IDR value of every line on each category for journals dated in the
     * month. Voided entries net to zero with their reversal.
     *
     * @return array<int, string>
     */
    protected function categorySums(string $from, string $to): array
    {
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.ledgerable_type', (new Category)->getMorphClass())
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->when($this->brandModel(), fn (Builder $query, $brand) => $query->where('journals.brand_id', $brand->id))
            ->groupBy('journal_lines.ledgerable_id')
            ->selectRaw('journal_lines.ledgerable_id as category_id, sum(journal_lines.idr_amount) as total')
            ->pluck('total', 'category_id')
            ->map(fn ($total): string => (string) $total)
            ->all();
    }

    /**
     * "(n Org)" for Gaji Staff and UM: people on that month's paid sheet.
     *
     * @return array<string, int>
     */
    protected function headcounts(int $year, int $month): array
    {
        $count = fn (PayType $type): int => PayItem::query()
            ->whereHas('payRun', fn (Builder $query) => $query->where(['pay_type' => $type, 'year' => $year, 'month' => $month, 'status' => PayRunStatus::Paid]))
            ->count();

        return ['payroll.gaji' => $count(PayType::Gaji), 'payroll.um' => $count(PayType::Um)];
    }
}
