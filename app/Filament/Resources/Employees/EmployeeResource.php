<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $baseCurrency = fn () => Currency::query()->where('is_base', true)->value('id');

        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Employee'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Fields::activeRelation('company_id', 'company', 'short_code')
                            ->label('PT')
                            ->required(),
                        TextInput::make('position')
                            ->label('Jabatan')
                            ->maxLength(255)
                            ->datalist(fn (): array => Employee::query()->distinct()->whereNotNull('position')->pluck('position')->all()),
                        DatePicker::make('join_date')
                            ->label('Join date')
                            ->helperText(__('LAMA KERJA is calculated from this.')),
                        Select::make('user_id')
                            ->label('Login user')
                            ->relationship('user', 'username')
                            ->searchable()
                            ->preload()
                            ->placeholder(__('None')),
                    ]),
                Section::make(__('Pay'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        Fields::money('salary_amount')
                            ->label('Gaji')
                            ->default(0)
                            ->required(),
                        Fields::activeRelation('salary_currency_id', 'salaryCurrency', 'code')
                            ->label('Gaji currency')
                            ->default($baseCurrency)
                            ->required(),
                        Fields::money('um_amount')
                            ->label('UM full')
                            ->default(0)
                            ->required(),
                        Fields::activeRelation('um_currency_id', 'umCurrency', 'code')
                            ->label('UM currency')
                            ->default($baseCurrency)
                            ->required(),
                    ]),
                Section::make(__('Bank'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Fields::activeRelation('bank_id', 'bank', 'name')
                            ->label('Bank'),
                        TextInput::make('account_number')
                            ->label('No. rek')
                            ->maxLength(50),
                        TextInput::make('account_name')
                            ->label('Nama rek')
                            ->maxLength(255),
                    ]),
                Textarea::make('notes')
                    ->label('Keterangan')
                    ->rows(2)
                    ->columnSpanFull(),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company', 'salaryCurrency', 'umCurrency', 'bank']))
            ->columns([
                TextColumn::make('company.short_code')->label('PT')->badge()->sortable(),
                TextColumn::make('position')->label('Jabatan')->sortable()->placeholder('-'),
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('length_of_service')
                    ->label('Lama kerja')
                    ->state(fn (Employee $record): ?string => $record->lengthOfService())
                    ->placeholder('-'),
                TextColumn::make('salary_amount')
                    ->label('Gaji')
                    ->money(fn (Employee $record): string => $record->salaryCurrency->code, locale: 'id')
                    ->alignEnd(),
                TextColumn::make('um_amount')
                    ->label('UM')
                    ->money(fn (Employee $record): string => $record->umCurrency->code, locale: 'id')
                    ->alignEnd(),
                TextColumn::make('bank.name')->label('Bank')->placeholder('-')->toggleable(),
                TextColumn::make('account_number')->label('No. rek')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Fields::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('company_id')->label('PT')->relationship('company', 'short_code'),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployees::route('/'),
        ];
    }
}
