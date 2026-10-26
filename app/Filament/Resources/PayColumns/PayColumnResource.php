<?php

namespace App\Filament\Resources\PayColumns;

use App\Enums\PayColumnKind;
use App\Enums\PayType;
use App\Filament\Resources\PayColumns\Pages\ManagePayColumns;
use App\Filament\Support\Fields;
use App\Models\PayColumn;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Custom pay-run columns (e.g. the sheet's hidden Gaji E–H / UM D–F).
 * H D defines them; we never need to know what they mean.
 */
class PayColumnResource extends Resource
{
    protected static ?string $model = PayColumn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Pay Columns';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('pay_type')
                    ->label('Pay run')
                    ->options(PayType::class)
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('kind')
                    ->options(PayColumnKind::class)
                    ->default(PayColumnKind::Info)
                    ->required()
                    ->helperText('Info: shown only. Addition / deduction: changes SISA (net pay).')
                    ->live(),
                Select::make('category_id')
                    ->label('Own P&L line')
                    ->relationship('category', 'name', fn ($query) => $query->where('is_active', true)->where('type', 'expense'))
                    ->placeholder('Book with Gaji / UM')
                    ->helperText('e.g. put "Lembur Offday" on its own line in Hitung Bersih.')
                    ->visible(fn ($get): bool => in_array($get('kind') instanceof PayColumnKind ? $get('kind')->value : $get('kind'), ['addition', 'deduction'], true)),
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
                TextColumn::make('pay_type')->label('Pay run')->badge()->sortable(),
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge(),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('pay_type')->label('Pay run')->options(PayType::class),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayColumns::route('/'),
        ];
    }
}
