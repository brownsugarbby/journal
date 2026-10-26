<?php

namespace App\Filament\Resources\Banks;

use App\Enums\BankType;
use App\Filament\Resources\Banks\Pages\ManageBanks;
use App\Filament\Support\Fields;
use App\Models\Bank;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BankResource extends Resource
{
    protected static ?string $model = Bank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Banks / E-wallets';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('short_name')
                    ->maxLength(20)
                    ->helperText('Label used in the old sheet tabs, e.g. MDR for Mandiri.'),
                Select::make('type')
                    ->options(BankType::class)
                    ->default(BankType::Bank)
                    ->required(),
                TextInput::make('country')
                    ->length(2)
                    ->default('ID')
                    ->required()
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                    ->helperText('2-letter code: ID, KH, TH...'),
                FileUpload::make('logo')
                    ->image()
                    ->disk('public')
                    ->directory('banks')
                    ->maxSize(512),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->disk('public')->imageHeight(24),
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('short_name')->placeholder('-'),
                TextColumn::make('type')->badge(),
                TextColumn::make('country'),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('type')->options(BankType::class),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBanks::route('/'),
        ];
    }
}
