<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

use function Filament\Support\get_model_label;

/**
 * Resource names (menu, titles, breadcrumbs, "New …" buttons) and the menu
 * group through lang/id.json, so the English model names follow APP_LOCALE.
 */
trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel ?? str(static::$modelLabel ?? get_model_label(static::getModel()))->plural()->toString());
    }

    public static function getNavigationLabel(): string|Htmlable
    {
        return static::$navigationLabel !== null ? __(static::$navigationLabel) : static::getTitleCasePluralModelLabel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        return is_string($group) ? __($group) : $group;
    }
}
