<?php

namespace App\Filament\Resources\CompanyAccounts;

use App\Enums\AccountKind;
use App\Enums\AccountPurpose;
use App\Enums\AccountTier;
use App\Enums\FeeType;
use App\Filament\Resources\CompanyAccounts\Pages\ManageCompanyAccounts;
use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use App\Filament\Support\Fields;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Support\AppSettings;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CompanyAccountResource extends Resource
{
    protected static ?string $model = CompanyAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Where the money is')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('kind')
                            ->options(AccountKind::class)
                            ->default(AccountKind::Bank)
                            ->required()
                            ->live(),
                        Fields::activeRelation('bank_id', 'bank', 'name')
                            ->label('Bank / e-wallet')
                            ->visible(fn (Get $get): bool => static::is($get('kind'), AccountKind::Bank))
                            ->required(fn (Get $get): bool => static::is($get('kind'), AccountKind::Bank)),
                        Fields::activeRelation('payment_gateway_id', 'paymentGateway', 'name')
                            ->label('Payment gateway')
                            ->visible(fn (Get $get): bool => static::is($get('kind'), AccountKind::Gateway))
                            ->required(fn (Get $get): bool => static::is($get('kind'), AccountKind::Gateway)),
                        Fields::activeRelation('currency_id', 'currency', 'code')
                            ->default(fn () => Currency::query()->where('is_base', true)->value('id'))
                            ->required(),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Name shown everywhere, e.g. "BCA DP/WD", "HOKI KES", "FLIPTECH".'),
                        TextInput::make('account_name')
                            ->label('Account name')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account number')
                            ->maxLength(50),
                        TextInput::make('holder')
                            ->label('Holder (HP)')
                            ->maxLength(255)
                            ->helperText('Who holds the phone / app, e.g. FUNG, KO HD.'),
                    ]),
                Section::make('Use')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('tier')
                            ->options(AccountTier::class)
                            ->default(AccountTier::Operational)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('max_balance', static::defaultMaxBalance($state))),
                        Select::make('purpose')
                            ->options(AccountPurpose::class)
                            ->default(AccountPurpose::DepositWithdraw)
                            ->required(),
                        Fields::activeRelation('brand_id', 'brand', 'code')
                            ->label('Brand')
                            ->placeholder('All brands (shared)')
                            ->visible(fn (Get $get): bool => static::is($get('tier'), AccountTier::Operational)),
                        Fields::activeRelation('company_id', 'company', 'short_code')
                            ->label('PT')
                            ->placeholder('None'),
                        TextInput::make('opening_balance')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('Balance at go-live; the ledger adds entries on top of it.'),
                        DatePicker::make('opening_date')
                            ->default(now()->startOfMonth()),
                        TextInput::make('max_balance')
                            ->numeric()
                            ->minValue(0)
                            ->default(fn (): int => static::defaultMaxBalance(AccountTier::Operational))
                            ->helperText('Alert when the book balance goes above this. Empty = no limit.'),
                    ]),
                Section::make('Fee override')
                    ->description(fn (Get $get): string => 'Leave empty to use the PGA default fees.')
                    ->columnSpanFull()
                    ->columns(4)
                    ->collapsed()
                    ->visible(fn (Get $get): bool => static::is($get('kind'), AccountKind::Gateway))
                    ->schema([
                        Select::make('dp_fee_type')->label('DP fee type')->options(FeeType::class)->live(),
                        PaymentGatewayResource::feeValueInput('dp')->required(fn (Get $get): bool => filled($get('dp_fee_type')))->default(null),
                        Select::make('wd_fee_type')->label('WD fee type')->options(FeeType::class)->live(),
                        PaymentGatewayResource::feeValueInput('wd')->required(fn (Get $get): bool => filled($get('wd_fee_type')))->default(null),
                    ]),
                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
                Fields::activeToggle(),
            ]);
    }

    public static function is(mixed $state, BackedEnum $case): bool
    {
        return ($state instanceof BackedEnum ? $state->value : $state) === $case->value;
    }

    public static function defaultMaxBalance(AccountTier|string|null $tier): int
    {
        $tier = $tier instanceof AccountTier ? $tier : AccountTier::tryFrom((string) $tier);

        return (int) app(AppSettings::class)->get($tier === AccountTier::Pusat
            ? 'default_max_balance_pusat'
            : 'default_max_balance_operational');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['bank', 'paymentGateway', 'brand', 'currency']))
            ->columns([
                TextColumn::make('label')->searchable()->sortable()->weight('bold'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('provider')
                    ->state(fn (CompanyAccount $record): string => $record->providerName()),
                TextColumn::make('tier')->badge()->sortable(),
                TextColumn::make('brand.code')->label('Brand')->badge()->placeholder('All'),
                TextColumn::make('account_number')->label('No. rek')->searchable()->placeholder('-')->toggleable(),
                TextColumn::make('holder')->label('HP')->placeholder('-')->toggleable(),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->money(fn (CompanyAccount $record): string => $record->currency->code, locale: 'id')
                    ->alignEnd(),
                TextColumn::make('max_balance')
                    ->label('Max')
                    ->money(fn (CompanyAccount $record): string => $record->currency->code, locale: 'id')
                    ->placeholder('No limit')
                    ->alignEnd(),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(AccountKind::class),
                SelectFilter::make('tier')->options(AccountTier::class),
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'code'),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('label');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyAccounts::route('/'),
        ];
    }
}
