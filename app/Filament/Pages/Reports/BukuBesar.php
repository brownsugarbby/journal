<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One account for one month, like its old sheet tab: SALDO AWAL, every
 * entry dated in the month with running SALDO, SALDO AKHIR.
 */
class BukuBesar extends Page implements HasTable
{
    use IsReportPage;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Buku Besar (per rekening)';

    protected static ?string $title = 'Buku Besar';

    protected static ?string $slug = 'laporan/buku-besar';

    #[Url]
    public ?string $month = null;

    #[Url]
    public ?string $account = null;

    public function mount(): void
    {
        $this->account ??= (string) CompanyAccount::query()->where('is_active', true)->orderBy('tier')->orderBy('label')->value('id');
    }

    protected function filterFields(): array
    {
        return [
            $this->monthField(),
            Select::make('account')
                ->label('Rekening')
                ->options(CompanyAccount::query()->orderBy('tier')->orderBy('label')->pluck('label', 'id')->all())
                ->native()
                ->selectablePlaceholder(false)
                ->live(),
        ];
    }

    protected function buildReport(): ReportTable
    {
        $account = CompanyAccount::query()->with('currency')->find($this->account);
        $start = $this->monthStart();
        $decimals = $account?->currency->decimals ?? 0;

        $columns = [
            ReportColumn::text('tgl', 'Tgl'),
            ReportColumn::text('no', 'No.'),
            ReportColumn::text('jenis', 'Jenis'),
            ReportColumn::text('keterangan', 'Keterangan'),
            ReportColumn::money('keluar', 'Keluar', $decimals),
            ReportColumn::money('masuk', 'Masuk', $decimals),
            ReportColumn::money('saldo', 'Saldo', $decimals),
            ReportColumn::text('op', 'Op'),
        ];

        if (! $account) {
            return new ReportTable('Buku Besar', __('Pick a rekening'), $columns, []);
        }

        $lines = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.ledgerable_type', $account->getMorphClass())
            ->where('journal_lines.ledgerable_id', $account->id);

        $before = (string) ((clone $lines)->whereDate('journals.entry_date', '<', $start)->sum('journal_lines.amount') ?: '0');
        $saldo = bcadd((string) $account->opening_balance, $before, 2);

        $rows = [['tgl' => '', 'keterangan' => 'SALDO AWAL', 'saldo' => $saldo, '_style' => 'section']];

        $inMonth = (clone $lines)
            ->whereDate('journals.entry_date', '>=', $start)
            ->whereDate('journals.entry_date', '<=', $start->copy()->endOfMonth())
            ->select('journal_lines.*')
            ->orderBy('journals.entry_date')
            ->orderBy('journal_lines.journal_id')
            ->with(['journal.member', 'journal.companyAccount', 'journal.targetAccount.currency', 'journal.category', 'journal.loanCounterparty', 'journal.partner', 'journal.payRun', 'journal.createdBy'])
            ->get();

        foreach ($inMonth as $line) {
            $saldo = bcadd($saldo, (string) $line->amount, 2);
            $journal = $line->journal;

            $rows[] = [
                'tgl' => $journal->entry_date->format('d/m/Y'),
                'no' => $journal->number,
                'jenis' => $journal->type->getLabel().($journal->isVoided() ? ' (VOID)' : ''),
                'keterangan' => $journal->description(),
                'keluar' => $line->amount < 0 ? ltrim((string) $line->amount, '-') : null,
                'masuk' => $line->amount > 0 ? (string) $line->amount : null,
                'saldo' => $saldo,
                'op' => $journal->createdBy?->username,
            ];
        }

        $rows[] = ReportTable::totalRow($rows, ['keluar', 'masuk'], 'keterangan', 'SALDO AKHIR') + ['saldo' => $saldo];

        return new ReportTable(
            title: 'Buku Besar '.$account->label,
            subtitle: $start->translatedFormat('F Y').' · '.$account->providerName().' · '.$account->currency->code,
            columns: $columns,
            rows: $rows,
            notes: [__('Entries in date order. A voided entry and its reversal both appear and cancel out.')],
        );
    }
}
