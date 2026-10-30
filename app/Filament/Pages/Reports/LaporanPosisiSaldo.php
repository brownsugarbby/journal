<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\LoanCounterparty;
use App\Models\Partner;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Where all the money stands at the end of a month (TOTAL SALDO DP/WD and
 * TOTAL SALDO pusat together), plus loans outstanding and partner balances.
 */
class LaporanPosisiSaldo extends Page implements HasTable
{
    use IsReportPage;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Posisi Saldo, Pinjaman & Partner';

    protected static ?string $title = 'Posisi Saldo';

    protected static ?string $slug = 'laporan/posisi-saldo';

    #[Url]
    public ?string $month = null;

    protected function filterFields(): array
    {
        return [$this->monthField()];
    }

    protected function buildReport(): ReportTable
    {
        $end = $this->monthStart()->endOfMonth();
        $asOf = $end->isFuture() ? today() : $end;

        $sums = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereDate('journals.entry_date', '<=', $asOf)
            ->groupBy('journal_lines.ledgerable_type', 'journal_lines.ledgerable_id')
            ->selectRaw('journal_lines.ledgerable_type as type, journal_lines.ledgerable_id as id, sum(journal_lines.amount) as units, sum(journal_lines.idr_amount) as idr')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => "{$row->type}:{$row->id}");

        $rows = [];
        $grandIdr = '0';

        foreach (CompanyAccount::query()->where('is_active', true)->with('currency')->orderBy('tier')->orderBy('currency_id')->orderBy('label')->get()->groupBy(fn (CompanyAccount $account) => $account->tier->getLabel()) as $tier => $accounts) {
            $section = ['nama' => "Rekening {$tier}", '_style' => 'section'];
            $lines = [];

            foreach ($accounts as $account) {
                $sum = $sums["{$account->getMorphClass()}:{$account->id}"] ?? null;
                $balance = bcadd((string) $account->opening_balance, (string) ($sum->units ?? 0), 2);
                $kurs = $account->averageKurs();
                $idr = $kurs === null ? null : bcmul($balance, $kurs, 2);

                $lines[] = [
                    'nama' => $account->label,
                    'cur' => $account->currency->code,
                    'saldo' => $balance,
                    'kurs' => $account->isBaseCurrency() ? null : $kurs,
                    'rp' => $idr,
                    '_warn' => $account->isOverMax($balance) ? ['saldo'] : [],
                ];
            }

            $subtotal = ReportTable::totalRow($lines, ['rp'], 'nama');
            $grandIdr = bcadd($grandIdr, (string) $subtotal['rp'], 2);
            array_push($rows, $section + ['rp' => $subtotal['rp']], ...$lines);
        }

        $rows[] = ['nama' => 'TOTAL SALDO (Rp)', 'rp' => $grandIdr, '_style' => 'total'];

        $rows[] = ['nama' => 'Pinjaman (> 0 owed to us, < 0 we owe)', '_style' => 'section'];
        foreach (LoanCounterparty::query()->orderBy('name')->get() as $counterparty) {
            $sum = $sums["{$counterparty->getMorphClass()}:{$counterparty->id}"] ?? null;
            $outstanding = bcadd((string) $counterparty->opening_balance, (string) ($sum->idr ?? 0), 2);

            if (bccomp($outstanding, '0', 2) !== 0) {
                $rows[] = ['nama' => $counterparty->name, 'cur' => 'IDR', 'rp' => $outstanding];
            }
        }

        $rows[] = ['nama' => 'Partner (modal − prive)', '_style' => 'section'];
        foreach (Partner::query()->orderBy('name')->get() as $partner) {
            $sum = $sums["{$partner->getMorphClass()}:{$partner->id}"] ?? null;
            $rows[] = ['nama' => $partner->name, 'cur' => 'IDR', 'rp' => bcsub((string) $partner->opening_balance, (string) ($sum->idr ?? 0), 2)];
        }

        return new ReportTable(
            title: 'Posisi Saldo',
            subtitle: 'Per '.$asOf->format('d/m/Y'),
            columns: [
                ReportColumn::text('nama', 'Nama'),
                ReportColumn::text('cur', 'Cur'),
                ReportColumn::money('saldo', 'Saldo', 2),
                ReportColumn::money('kurs', 'Kurs', 2),
                ReportColumn::money('rp', 'Nilai Rp'),
            ],
            rows: $rows,
            notes: [__('THB / USD valued at the current average kurs of each account. Red saldo = above the account\'s max balance.')],
        );
    }
}
