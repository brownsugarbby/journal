<?php

namespace App\Filament\Pages;

use App\Enums\AccountKind;
use App\Filament\Support\Fields;
use App\Models\BalanceCheck;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Live replacement of the TOTAL SALDO DP/WD tab: book balance per
 * operational account, banks and gateways totalled separately.
 */
class SaldoDpWd extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Saldo DP/WD';

    protected static ?string $title = 'Saldo DP/WD';

    protected static ?string $slug = 'saldo-dp-wd';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && ! $user->isStaffCs();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => $this->totalsLine())->weight('bold'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return Builder<CompanyAccount>
     */
    protected function accountsQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->accessibleAccounts()
            ->with(['bank', 'paymentGateway', 'brand'])
            ->addSelect([
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

    protected function totalsLine(): string
    {
        $totals = $this->accountsQuery()->get()
            ->groupBy(fn (CompanyAccount $account): string => $account->kind === AccountKind::Gateway ? 'gateway' : 'bank')
            ->map(fn ($accounts) => $accounts->reduce(fn (string $sum, CompanyAccount $account) => bcadd($sum, $this->balanceOf($account), 2), '0'));

        return 'TOTAL SALDO bank '.Fields::rupiah($totals['bank'] ?? 0).'   ·   PGA '.Fields::rupiah($totals['gateway'] ?? 0);
    }

    protected function balanceOf(CompanyAccount $account): string
    {
        return bcadd((string) $account->opening_balance, (string) $account->lines_sum, 2);
    }

    public function table(Table $table): Table
    {
        return $table
            // Grouping needs rows ordered by the group column first.
            ->query(fn (): Builder => $this->accountsQuery()->reorder('kind')->orderBy('label'))
            ->columns([
                TextColumn::make('label')->label('Nama rekening')->weight('bold')->searchable(),
                TextColumn::make('provider')->state(fn (CompanyAccount $record): string => $record->providerName()),
                TextColumn::make('brand.code')->label('Brand')->badge()->placeholder('All'),
                TextColumn::make('balance')
                    ->label('Jumlah')
                    ->state(fn (CompanyAccount $record): string => $this->balanceOf($record))
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->color(fn (CompanyAccount $record): ?string => $record->isOverMax($this->balanceOf($record)) ? 'danger' : null)
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('max_balance')
                    ->label('Max')
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->placeholder('No limit')
                    ->alignEnd(),
                TextColumn::make('over')
                    ->label('')
                    ->state(fn (CompanyAccount $record): ?string => $record->isOverMax($this->balanceOf($record)) ? 'Over max' : null)
                    ->badge()
                    ->color('danger'),
                TextColumn::make('last_difference')
                    ->label('Cek saldo')
                    ->formatStateUsing(fn ($state): string => (float) $state === 0.0 ? 'Match' : 'Selisih '.Fields::rupiah($state))
                    ->color(fn ($state): string => (float) $state === 0.0 ? 'success' : 'danger')
                    ->placeholder('Never'),
            ])
            ->defaultGroup(Group::make('kind')->label('')->titlePrefixedWithLabel(false))
            ->recordUrl(fn (CompanyAccount $record): string => Jurnal::getUrl(['tab' => "acc-{$record->id}"]))
            ->paginated(false);
    }
}
