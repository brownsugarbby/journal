<?php

namespace App\Filament\Resources\PaymentGateways;

use App\Enums\FeePaidBy;
use App\Enums\FeeType;
use App\Filament\Resources\PaymentGateways\Pages\ManagePaymentGateways;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayFeeHistory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentGatewayResource extends Resource
{
    protected static ?string $model = PaymentGateway::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'payment gateway (PGA)';

    protected static ?string $pluralModelLabel = 'payment gateways (PGA)';

    protected static ?string $navigationLabel = 'Payment Gateways';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                    // The code is part of the auto-created fee category codes.
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Fee lines appear in the P&L as "Potongan DP/WD PGA {name}".'),
                TextInput::make('dashboard_url')
                    ->label('Dashboard URL')
                    ->url()
                    ->maxLength(255),
                Fields::activeRelation('currency_id', 'currency', 'code')
                    ->default(fn () => Currency::query()->where('is_base', true)->value('id'))
                    ->required(),
                Section::make('Default fees (POTONGAN)')
                    ->description('Pre-filled on DP/WD entries. A gateway account can override them. Every change is kept in the fee history.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('dp_fee_type')
                            ->label('DP fee type')
                            ->options(FeeType::class)
                            ->default(FeeType::Flat)
                            ->required()
                            ->live(),
                        static::feeValueInput('dp'),
                        Select::make('wd_fee_type')
                            ->label('WD fee type')
                            ->options(FeeType::class)
                            ->default(FeeType::Flat)
                            ->required()
                            ->live(),
                        static::feeValueInput('wd'),
                        Select::make('wd_fee_paid_by')
                            ->label('WD fee paid by')
                            ->options(FeePaidBy::class)
                            ->default(FeePaidBy::Company)
                            ->required(),
                    ]),
                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
                Fields::activeToggle(),
            ]);
    }

    public static function feeValueInput(string $direction): TextInput
    {
        return TextInput::make("{$direction}_fee_value")
            ->label(strtoupper($direction).' fee')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->required()
            ->prefix(fn (Get $get): ?string => static::isPercent($get("{$direction}_fee_type")) ? null : 'Rp')
            ->suffix(fn (Get $get): ?string => static::isPercent($get("{$direction}_fee_type")) ? '%' : null)
            ->maxValue(fn (Get $get): ?int => static::isPercent($get("{$direction}_fee_type")) ? 100 : null);
    }

    public static function isPercent(FeeType|string|null $type): bool
    {
        return ($type instanceof FeeType ? $type : FeeType::tryFrom((string) $type)) === FeeType::Percent;
    }

    public static function formatFee(FeeType|string|null $type, mixed $value): string
    {
        return static::isPercent($type)
            ? rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',').' %'
            : 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('currency.code')->label('Currency'),
                TextColumn::make('dp_fee_value')
                    ->label('DP fee')
                    ->formatStateUsing(fn (PaymentGateway $record): string => static::formatFee($record->dp_fee_type, $record->dp_fee_value)),
                TextColumn::make('wd_fee_value')
                    ->label('WD fee')
                    ->formatStateUsing(fn (PaymentGateway $record): string => static::formatFee($record->wd_fee_type, $record->wd_fee_value)),
                TextColumn::make('wd_fee_paid_by')->label('WD fee paid by')->badge(),
                TextColumn::make('accounts_count')->counts('accounts')->label('Accounts'),
                Fields::activeColumn(),
            ])
            ->filters([Fields::activeFilter()])
            ->recordActions([
                EditAction::make(),
                Action::make('feeHistory')
                    ->label('Fee history')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        RepeatableEntry::make('feeHistories')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('effective_from')->label('From')->dateTime('d M Y H:i'),
                                TextEntry::make('dp_fee_value')
                                    ->label('DP fee')
                                    ->formatStateUsing(fn (PaymentGatewayFeeHistory $record): string => static::formatFee($record->dp_fee_type, $record->dp_fee_value)),
                                TextEntry::make('wd_fee_value')
                                    ->label('WD fee')
                                    ->formatStateUsing(fn (PaymentGatewayFeeHistory $record): string => static::formatFee($record->wd_fee_type, $record->wd_fee_value)),
                                TextEntry::make('createdBy.name')->label('By')->placeholder('System'),
                            ]),
                    ]),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePaymentGateways::route('/'),
        ];
    }
}
