<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\IsReportPage;
use App\Models\CompanyAccount;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use UnitEnum;

/**
 * "Saldo bank dan saldo docs harus sama": every active account's book
 * balance now against its last Cek Saldo. Red = a difference, or never
 * checked.
 */
class LaporanRekonsiliasi extends Page implements HasTable
{
    use IsReportPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Laporan Rekonsiliasi';

    protected static ?string $title = 'Laporan Rekonsiliasi';

    protected static ?string $slug = 'laporan/rekonsiliasi';

    protected function buildReport(): ReportTable
    {
        $rows = CompanyAccount::query()
            ->where('is_active', true)
            ->with(['currency', 'balanceChecks.createdBy'])
            ->orderBy('tier')
            ->orderBy('label')
            ->get()
            ->map(function (CompanyAccount $account): array {
                $check = $account->balanceChecks->first();
                $row = [
                    'rekening' => $account->label,
                    'tier' => $account->tier->getLabel(),
                    'cur' => $account->currency->code,
                    'buku' => $account->balance(),
                    'actual' => $check?->actual_balance,
                    'buku_saat_cek' => $check?->book_balance,
                    'selisih' => $check?->difference,
                    'dicek' => $check ? $check->created_at->format('d/m/Y H:i').' · '.$check->createdBy?->username : 'Never',
                ];

                $row['_warn'] = ! $check ? ['dicek'] : (! $check->isMatching() ? ['selisih'] : []);

                return $row;
            })
            ->all();

        return new ReportTable(
            title: 'Laporan Rekonsiliasi',
            subtitle: 'As of '.now()->format('d/m/Y H:i'),
            columns: [
                ReportColumn::text('rekening', 'Rekening'),
                ReportColumn::text('tier', 'Tier'),
                ReportColumn::text('cur', 'Cur'),
                ReportColumn::money('buku', 'Saldo buku sekarang', 2),
                ReportColumn::money('buku_saat_cek', 'Saldo buku saat cek', 2),
                ReportColumn::money('actual', 'Saldo bank (cek)', 2),
                ReportColumn::money('selisih', 'Selisih', 2),
                ReportColumn::text('dicek', 'Dicek'),
            ],
            rows: $rows,
            notes: ['Selisih = saldo bank − saldo buku at the time of the last Cek Saldo. Fix a selisih with a Penyesuaian on Kas Pusat or by finding the missing entry.'],
        );
    }
}
