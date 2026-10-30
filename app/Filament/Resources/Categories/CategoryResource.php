<?php

namespace App\Filament\Resources\Categories;

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\PlGroup;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\Fields;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CategoryResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'P&L Categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Auto categories are wired to system logic by source_key: their
        // code, type and source stay fixed; name, group and order can change.
        $isAuto = fn (?Category $record): bool => $record?->source === CategorySource::Auto;

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtolower($state))
                    ->disabled($isAuto),
                Select::make('pl_group')
                    ->label('P&L group')
                    ->options(PlGroup::class)
                    ->default(PlGroup::BiayaVariable)
                    ->required(),
                Select::make('type')
                    ->options(CategoryType::class)
                    ->default(CategoryType::Expense)
                    ->required()
                    ->disabled($isAuto),
                TextInput::make('sort_order')
                    ->numeric()
                    ->integer()
                    ->default(fn (): int => (int) Category::query()->max('sort_order') + 10)
                    ->required(),
                Toggle::make('show_headcount')
                    ->label('Show headcount "(n Org)"')
                    ->inline(false),
                Fields::activeToggle(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->color('gray')->searchable()->toggleable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('source')->badge(),
                IconColumn::make('show_headcount')->label('Org')->boolean(),
                Fields::activeColumn(),
            ])
            ->defaultGroup(
                Group::make('pl_group')
                    ->label('P&L group')
                    ->collapsible()
                    // Same order as the Hitung Bersih sheet, not alphabetical.
                    ->orderQueryUsing(fn (Builder $query): Builder => $query->orderByRaw(
                        'case pl_group '.collect(PlGroup::cases())
                            ->map(fn (PlGroup $group, int $i): string => "when '{$group->value}' then {$i}")
                            ->join(' ').' end',
                    )),
            )
            ->filters([
                SelectFilter::make('pl_group')->label('P&L group')->options(PlGroup::class),
                SelectFilter::make('source')->options(CategorySource::class),
                Fields::activeFilter(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('sort_order')
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
