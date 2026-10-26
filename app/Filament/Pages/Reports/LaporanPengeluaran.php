<?php

namespace App\Filament\Pages\Reports;

use App\Enums\JournalType;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\Category;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * PENGELUARAN KANTOR for a month, grouped by category with subtotals.
 * Amounts in Rp (baht / dollar spending valued at its kurs).
 */
class LaporanPengeluaran extends Page implements HasTable
{
    use IsReportPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Laporan Pengeluaran';

    protected static ?string $title = 'Laporan Pengeluaran Kantor';

    protected static ?string $slug = 'laporan/pengeluaran';

    #[Url]
    public ?string $month = null;

    #[Url]
    public ?string $brand = null;

    #[Url]
    public ?string $category = null;

    protected function filterFields(): array
    {
        return [
            $this->monthField(),
            $this->brandField('All (incl. shared)'),
            Select::make('category')
                ->label('Kategori')
                ->options(Category::query()->where('source', 'manual')->where('type', 'expense')->orderBy('sort_order')->pluck('name', 'id')->all())
                ->placeholder('All categories')
                ->native()
                ->live(),
        ];
    }

    protected function buildReport(): ReportTable
    {
        $start = $this->monthStart();

        $journals = Journal::query()
            ->where('type', JournalType::Expense)
            ->whereNull('voided_at')
            ->whereNull('reversal_of_id')
            ->whereDate('entry_date', '>=', $start)
            ->whereDate('entry_date', '<=', $start->copy()->endOfMonth())
            ->when($this->brandModel(), fn (Builder $query, $brand) => $query->where('brand_id', $brand->id))
            ->when(filled($this->category), fn (Builder $query) => $query->where('category_id', $this->category))
            ->with(['category', 'companyAccount', 'expenseStatus', 'brand', 'createdBy'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        // Rp value = the category line (keluar +, refund −).
        $idr = JournalLine::query()
            ->whereIn('journal_id', $journals->pluck('id'))
            ->where('ledgerable_type', (new Category)->getMorphClass())
            ->pluck('idr_amount', 'journal_id');

        $rows = [];

        foreach ($journals->groupBy('category_id') as $group) {
            $section = ['tgl' => '', 'keterangan' => $group->first()->category->name, '_style' => 'section'];
            $lines = $group->map(fn (Journal $journal): array => [
                'tgl' => $journal->entry_date->format('d/m/Y'),
                'keterangan' => $journal->note,
                'bank' => $journal->companyAccount?->label,
                'status' => $journal->expenseStatus?->name,
                'web' => $journal->brand?->code ?? 'Shared',
                'keluar' => (float) $idr[$journal->id] > 0 ? (string) $idr[$journal->id] : null,
                'masuk' => (float) $idr[$journal->id] < 0 ? ltrim((string) $idr[$journal->id], '-') : null,
                'op' => $journal->createdBy?->username,
            ])->all();

            $subtotal = ReportTable::totalRow($lines, ['keluar', 'masuk'], 'keterangan', 'Subtotal');
            array_push($rows, $section + ['keluar' => $subtotal['keluar'], 'masuk' => $subtotal['masuk']], ...$lines);
        }

        $total = ReportTable::totalRow(array_filter($rows, fn (array $row): bool => ! isset($row['_style'])), ['keluar', 'masuk'], 'keterangan');
        $rows[] = $total;
        $rows[] = ['keterangan' => 'Total bersih (keluar − masuk)', 'keluar' => bcsub((string) $total['keluar'], (string) $total['masuk'], 2), '_style' => 'total'];

        return new ReportTable(
            title: 'Laporan Pengeluaran Kantor',
            subtitle: $start->translatedFormat('F Y').' · '.$this->brandLabel(),
            columns: [
                ReportColumn::text('tgl', 'Tgl'),
                ReportColumn::text('keterangan', 'Nama'),
                ReportColumn::text('bank', 'Bank'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::text('web', 'Web'),
                ReportColumn::money('keluar', 'Keluar (Rp)'),
                ReportColumn::money('masuk', 'Masuk (Rp)'),
                ReportColumn::text('op', 'Op'),
            ],
            rows: $rows,
        );
    }
}
