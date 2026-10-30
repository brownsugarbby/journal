<?php

namespace App\Filament\Pages\Reports;

use App\Enums\JournalType;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\Journal;
use App\Models\Member;
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
 * Per member for a month: DP, WD, bonus, cashback and net (DP − WD),
 * biggest depositors first.
 */
class LaporanMember extends Page implements HasTable
{
    use IsReportPage;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Laporan Member';

    protected static ?string $title = 'Laporan Member';

    protected static ?string $slug = 'laporan/member';

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
        $sum = fn (JournalType $type): string => "sum(case when type = '{$type->value}' then amount else 0 end)";
        $count = fn (JournalType $type): string => "sum(case when type = '{$type->value}' then 1 else 0 end)";

        $totals = Journal::query()
            ->whereNotNull('member_id')
            ->whereNull('voided_at')
            ->whereNull('reversal_of_id')
            ->whereDate('entry_date', '>=', $start)
            ->whereDate('entry_date', '<=', $start->copy()->endOfMonth())
            ->when($this->brandModel(), fn (Builder $query, $brand) => $query->where('brand_id', $brand->id))
            ->groupBy('member_id')
            ->selectRaw('member_id')
            ->selectRaw($count(JournalType::Deposit).' as dp_count, '.$sum(JournalType::Deposit).' as dp')
            ->selectRaw($count(JournalType::Withdraw).' as wd_count, '.$sum(JournalType::Withdraw).' as wd')
            ->selectRaw($sum(JournalType::Bonus).' as bonus, '.$sum(JournalType::Cashback).' as cashback')
            ->toBase()
            ->get();

        $members = Member::query()->with(['brand', 'bank'])->findMany($totals->pluck('member_id'))->keyBy('id');

        $rows = $totals
            ->map(fn (object $row): array => [
                'brand' => $members[$row->member_id]?->brand->code,
                'username' => $members[$row->member_id]?->member_id,
                'nama_rek' => $members[$row->member_id]?->account_name,
                'bank' => $members[$row->member_id]?->bank?->short_name,
                'dp_count' => (int) $row->dp_count,
                'dp' => (string) $row->dp,
                'wd_count' => (int) $row->wd_count,
                'wd' => (string) $row->wd,
                'bonus' => (string) $row->bonus,
                'cashback' => (string) $row->cashback,
                'net' => bcsub((string) $row->dp, (string) $row->wd, 2),
            ])
            ->sortByDesc(fn (array $row): float => (float) $row['dp'])
            ->values()
            ->all();

        $rows[] = ReportTable::totalRow($rows, ['dp_count', 'dp', 'wd_count', 'wd', 'bonus', 'cashback', 'net'], 'username');

        return new ReportTable(
            title: 'Laporan Member',
            subtitle: $start->translatedFormat('F Y').' · '.$this->brandLabel(),
            columns: [
                ReportColumn::text('brand', 'Brand'),
                ReportColumn::text('username', 'Username'),
                ReportColumn::text('nama_rek', 'Nama rek'),
                ReportColumn::text('bank', 'Bank'),
                ReportColumn::number('dp_count', 'x DP'),
                ReportColumn::money('dp', 'Total DP'),
                ReportColumn::number('wd_count', 'x WD'),
                ReportColumn::money('wd', 'Total WD'),
                ReportColumn::money('bonus', 'Bonus'),
                ReportColumn::money('cashback', 'Cashback'),
                ReportColumn::money('net', 'DP − WD'),
            ],
            rows: $rows,
        );
    }
}
