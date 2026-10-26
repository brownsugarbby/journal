<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Small builders shared by the master data resources.
 */
class Fields
{
    /**
     * Rupiah input formatted while typing (1.250.000), saved as a plain number.
     */
    public static function money(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
            ->stripCharacters('.')
            ->numeric()
            ->minValue(0)
            ->inputMode('numeric');
    }

    /**
     * Rupiah display: 1.250.000 (no currency prefix, like the sheets).
     */
    public static function rupiah(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 0, ',', '.');
    }

    public static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Active')
            ->default(true)
            ->inline(false)
            ->helperText('Inactive rows are hidden from dropdowns but kept for history.');
    }

    public static function activeColumn(): IconColumn
    {
        return IconColumn::make('is_active')
            ->label('Active')
            ->boolean()
            ->sortable();
    }

    public static function activeFilter(): TernaryFilter
    {
        return TernaryFilter::make('is_active')
            ->label('Active')
            ->default(true);
    }

    /**
     * Relationship select that only offers active rows, but still shows an
     * inactive value already saved on the record.
     */
    public static function activeRelation(string $field, string $relationship, string $titleAttribute): Select
    {
        return Select::make($field)
            ->relationship(
                $relationship,
                $titleAttribute,
                fn (Builder $query, ?string $state) => $query->where(
                    fn (Builder $query) => $query->where('is_active', true)->when($state, fn (Builder $query) => $query->orWhere('id', $state)),
                ),
            )
            ->searchable()
            ->preload();
    }
}
