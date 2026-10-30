<?php

namespace App\Filament\Resources\BonusTypes;

use App\Enums\BonusKind;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\BonusTypes\Pages\ManageBonusTypes;
use App\Filament\Support\Fields;
use App\Models\BonusType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BonusTypeResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = BonusType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('kind')
                    ->options(BonusKind::class)
                    ->default(BonusKind::Bonus)
                    ->required()
                    ->live(),
                Fields::activeRelation('brand_id', 'brand', 'code')
                    ->label('Brand')
                    ->placeholder(__('All brands')),
                TextInput::make('default_percentage')
                    ->label('Default %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->helperText(__('Cashback = WINLOSE × this %.'))
                    ->visible(fn (Get $get): bool => ($get('kind') instanceof BonusKind ? $get('kind') : BonusKind::tryFrom((string) $get('kind'))) === BonusKind::Cashback)
                    ->required(fn (Get $get): bool => ($get('kind') instanceof BonusKind ? $get('kind') : BonusKind::tryFrom((string) $get('kind'))) === BonusKind::Cashback),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('brand.code')->label('Brand')->badge()->placeholder(__('All')),
                TextColumn::make('default_percentage')->label('Default %')->suffix(' %')->placeholder('-'),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(BonusKind::class),
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'code'),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBonusTypes::route('/'),
        ];
    }
}
