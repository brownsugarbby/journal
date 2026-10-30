<?php

namespace App\Filament\Resources\Partners;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Partners\Pages\ManagePartners;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Models\Partner;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PartnerResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Partner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('profit_share_percentage')
                    ->label('Profit share')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),
                Fields::activeRelation('currency_id', 'currency', 'code')
                    ->default(fn () => Currency::query()->where('is_base', true)->value('id'))
                    ->required(),
                Fields::money('opening_balance', allowNegative: true)
                    ->default(0)
                    ->required(),
                Textarea::make('notes')
                    ->rows(2),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('profit_share_percentage')->label('Profit share')->suffix(' %')->placeholder('-'),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->money(fn (Partner $record): string => $record->currency->code, locale: 'id')
                    ->alignEnd(),
                Fields::activeColumn(),
            ])
            ->filters([Fields::activeFilter()])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePartners::route('/'),
        ];
    }
}
