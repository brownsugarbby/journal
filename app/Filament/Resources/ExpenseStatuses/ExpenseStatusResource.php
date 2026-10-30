<?php

namespace App\Filament\Resources\ExpenseStatuses;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ExpenseStatuses\Pages\ManageExpenseStatuses;
use App\Filament\Support\Fields;
use App\Models\ExpenseStatus;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ExpenseStatusResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = ExpenseStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('color')
                    ->options([
                        'gray' => 'Gray',
                        'info' => 'Blue',
                        'success' => 'Green',
                        'warning' => 'Orange',
                        'danger' => 'Red',
                    ])
                    ->default('gray')
                    ->required(),
                TextInput::make('sort_order')
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->required(),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')
                    ->badge()
                    ->color(fn (ExpenseStatus $record): string => $record->color)
                    ->searchable(),
                Fields::activeColumn(),
            ])
            ->filters([Fields::activeFilter()])
            ->recordActions([EditAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseStatuses::route('/'),
        ];
    }
}
