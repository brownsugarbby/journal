<?php

namespace App\Filament\Resources\GamePanels;

use App\Filament\Resources\GamePanels\Pages\ManageGamePanels;
use App\Filament\Support\Fields;
use App\Models\GamePanel;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class GamePanelResource extends Resource
{
    protected static ?string $model = GamePanel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fields::activeRelation('brand_id', 'brand', 'code')
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('panel_url')
                    ->label('Panel URL')
                    ->url()
                    ->maxLength(255)
                    ->helperText('Login credentials are not stored here.'),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.code')->label('Brand')->badge()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('panel_url')->label('URL')->url(fn (GamePanel $record): ?string => $record->panel_url, true)->placeholder('-'),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'code'),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGamePanels::route('/'),
        ];
    }
}
