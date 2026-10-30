<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextInputColumn;

/**
 * Editable grid cell for amounts: shows and types 1.250.000, saves 1250000.
 */
class MoneyInputColumn extends TextInputColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mask(Fields::moneyMask());
        $this->extraInputAttributes(['style' => 'min-width: 7.5rem; text-align: right']);
    }

    public function getState(): mixed
    {
        $state = parent::getState();

        return is_numeric($state) ? Fields::rupiah($state) : $state;
    }

    public function validate(mixed $input): void
    {
        parent::validate(Fields::parseMoney($input));
    }

    public function updateState(mixed $state): mixed
    {
        return parent::updateState(Fields::parseMoney($state));
    }
}
