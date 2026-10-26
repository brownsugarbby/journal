<?php

namespace App\Filament\Resources\LoanCounterparties;

use App\Enums\LoanCounterpartyType;
use App\Filament\Resources\CompanyAccounts\CompanyAccountResource;
use App\Filament\Resources\LoanCounterparties\Pages\ManageLoanCounterparties;
use App\Filament\Support\Fields;
use App\Models\LoanCounterparty;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class LoanCounterpartyResource extends Resource
{
    protected static ?string $model = LoanCounterparty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Loan Counterparties';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(LoanCounterpartyType::class)
                    ->default(LoanCounterpartyType::External)
                    ->required()
                    ->live(),
                Fields::activeRelation('employee_id', 'employee', 'name')
                    ->label('Employee')
                    ->helperText('Lets repayments be deducted in payroll later.')
                    ->visible(fn (Get $get): bool => CompanyAccountResource::is($get('type'), LoanCounterpartyType::Employee)),
                Fields::activeRelation('partner_id', 'partner', 'name')
                    ->label('Partner')
                    ->visible(fn (Get $get): bool => CompanyAccountResource::is($get('type'), LoanCounterpartyType::Partner)),
                TextInput::make('opening_balance')
                    ->label('Opening balance (Rp)')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->helperText('Outstanding at go-live: positive = they owe us, negative = we owe them.'),
                TextInput::make('interest_rate')
                    ->label('Interest (Bunga)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('% / month'),
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
                TextColumn::make('type')->badge(),
                TextColumn::make('linked')
                    ->label('Linked to')
                    ->state(fn (LoanCounterparty $record): ?string => $record->employee?->name ?? $record->partner?->name)
                    ->placeholder('-'),
                TextColumn::make('opening_balance')->label('Opening')->numeric(0, ',', '.')->alignEnd(),
                TextColumn::make('interest_rate')->label('Bunga')->suffix(' %')->placeholder('-'),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('type')->options(LoanCounterpartyType::class),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLoanCounterparties::route('/'),
        ];
    }
}
