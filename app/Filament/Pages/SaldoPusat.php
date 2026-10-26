<?php

namespace App\Filament\Pages;

use App\Enums\AccountTier;
use App\Filament\Support\Fields;
use App\Models\BalanceCheck;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\LoanCounterparty;
use App\Models\Partner;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Live replacement of the TOTAL SALDO pusat tab: every pusat / cash account
 * in its own currency and in Rp (at its average kurs), this month's office
 * expense, loans outstanding and partner balances.
 */
class SaldoPusat extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Saldo Pusat';

    protected static ?string $title = 'Saldo Pusat';

    protected static ?string $slug = 'saldo-pusat';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->hasFullAccess() ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.saldo-pusat.summary')->viewData(fn (): array => $this->summary()),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return Builder<CompanyAccount>
     */
    protected function accountsQuery(): Builder
    {
        return CompanyAccount::query()
            ->where('is_active', true)
            ->where('tier', AccountTier::Pusat)
            ->with(['currency', 'bank'])
            ->addSelect([
                'company_accounts.*',
                'lines_sum' => JournalLine::query()
                    ->selectRaw('coalesce(sum(amount), 0)')
                    ->where('ledgerable_type', (new CompanyAccount)->getMorphClass())
                    ->whereColumn('ledgerable_id', 'company_accounts.id'),
                'last_difference' => BalanceCheck::query()
                    ->select('difference')
                    ->whereColumn('company_account_id', 'company_accounts.id')
                    ->latest('id')
                    ->limit(1),
            ]);
    }

    /**
     * @return Collection<int, CompanyAccount>
     */
    protected function accounts(): Collection
    {
        return once(fn () => $this->accountsQuery()->get());
    }

    protected function balanceOf(CompanyAccount $account): string
    {
        return bcadd((string) $account->opening_balance, (string) $account->lines_sum, 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $currencyTotals = $this->accounts()
            ->groupBy('currency_id')
            ->map(function (Collection $accounts): array {
                $currency = $accounts->first()->currency;

                return [
                    'code' => $currency->code,
                    'decimals' => $currency->decimals,
                    'is_base' => $currency->is_base,
                    'balance' => $accounts->reduce(fn (string $sum, CompanyAccount $account) => bcadd($sum, $this->balanceOf($account), 2), '0'),
                    'idr' => $accounts->reduce(fn (string $sum, CompanyAccount $account) => bcadd($sum, $account->idrValue($this->balanceOf($account)) ?? '0', 2), '0'),
                ];
            })
            ->sortByDesc('is_base')
            ->values();

        $period = Period::query()->where(['year' => today()->year, 'month' => today()->month])->first();

        return [
            'currencyTotals' => $currencyTotals,
            'grandTotalIdr' => $currencyTotals->reduce(fn (string $sum, array $total) => bcadd($sum, $total['idr'], 2), '0'),
            'monthLabel' => today()->translatedFormat('F Y'),
            'expenseTotal' => $period ? (string) (Ledger::expenses($period->id)->sum('idr_value') ?: '0') : '0',
            'loans' => LoanCounterparty::query()->orderBy('name')->get()
                ->map(fn (LoanCounterparty $counterparty): array => ['name' => $counterparty->name, 'balance' => $counterparty->balance()])
                ->reject(fn (array $loan): bool => bccomp($loan['balance'], '0', 2) === 0)
                ->values(),
            'partners' => Partner::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Partner $partner): array => ['name' => $partner->name, 'balance' => $partner->balance()]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Grouping needs rows ordered by the group column first.
            ->query(fn (): Builder => $this->accountsQuery()->orderBy('currency_id')->orderBy('label'))
            ->columns([
                TextColumn::make('label')->label('Nama rekening')->weight('bold')->searchable(),
                TextColumn::make('provider')->state(fn (CompanyAccount $record): string => $record->providerName()),
                TextColumn::make('holder')->label('HP')->placeholder('-'),
                TextColumn::make('balance')
                    ->label('Jumlah')
                    ->state(fn (CompanyAccount $record): string => $this->balanceOf($record))
                    ->formatStateUsing(fn ($state, CompanyAccount $record): string => number_format((float) $state, $record->currency->decimals, ',', '.'))
                    ->color(fn (CompanyAccount $record): ?string => $record->isOverMax($this->balanceOf($record)) ? 'danger' : null)
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('kurs')
                    ->label('Avg kurs')
                    ->state(fn (CompanyAccount $record): ?string => $record->isBaseCurrency() ? null : $record->averageKurs())
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 4, ',', '.'), '0'), ','))
                    ->placeholder('-')
                    ->alignEnd(),
                TextColumn::make('idr_value')
                    ->label('Nilai Rp')
                    ->state(fn (CompanyAccount $record): ?string => $record->idrValue($this->balanceOf($record)))
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->placeholder('No kurs yet')
                    ->alignEnd(),
                TextColumn::make('max_balance')->label('Max')->formatStateUsing(fn ($state) => Fields::rupiah($state))->placeholder('No limit')->alignEnd(),
                TextColumn::make('last_difference')
                    ->label('Cek saldo')
                    ->formatStateUsing(fn ($state): string => (float) $state === 0.0 ? 'Match' : 'Selisih '.Fields::rupiah($state))
                    ->color(fn ($state): string => (float) $state === 0.0 ? 'success' : 'danger')
                    ->placeholder('Never'),
            ])
            ->defaultGroup(Group::make('currency.code')->label('')->titlePrefixedWithLabel(false))
            ->recordUrl(fn (CompanyAccount $record): string => Pusat::getUrl(['tab' => "acc-{$record->id}"]))
            ->paginated(false);
    }
}
