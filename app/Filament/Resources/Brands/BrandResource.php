<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Support\Fields;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Brands / Websites';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                    ->helperText('Short code used in reports, e.g. KES, JALU.'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('accounts_count')->counts('accounts')->label('Accounts'),
                Fields::activeColumn(),
            ])
            ->filters([Fields::activeFilter()])
            ->recordActions([EditAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrands::route('/'),
        ];
    }
}
