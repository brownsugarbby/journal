<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Support\Fields;
use App\Models\Currency;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CurrencyResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->length(3)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                    ->helperText(__('ISO code: IDR, USD, THB.')),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('symbol')
                    ->required()
                    ->maxLength(10),
                TextInput::make('decimals')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(4)
                    ->default(2)
                    ->required(),
                // The base currency is set by the seeder (IDR); reports convert into it.
                Toggle::make('is_base')
                    ->label('Base currency')
                    ->disabled()
                    ->dehydrated(false)
                    ->inline(false),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('symbol'),
                TextColumn::make('decimals'),
                IconColumn::make('is_base')->label('Base')->boolean(),
                Fields::activeColumn(),
            ])
            ->filters([Fields::activeFilter()])
            ->recordActions([EditAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencies::route('/'),
        ];
    }
}
