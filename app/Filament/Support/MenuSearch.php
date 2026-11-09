<?php

namespace App\Filament\Support;

use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Str;

/**
 * The topbar "Cari" box finds pages by name or group ("gaji", "laporan").
 * It covers every page the user may open, also those left out of the
 * trimmed menu (Jurnal, Kas Pusat, Pengguna…), so they stay one search away.
 */
class MenuSearch implements GlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $words = array_filter(explode(' ', Str::lower(trim($query))));

        $results = Menu::accessibleItems()
            ->filter(function (NavigationItem $item) use ($words): bool {
                $haystack = Str::lower((string) $item->getLabel().' '.$item->getGroup());

                return collect($words)->every(fn (string $word): bool => str_contains($haystack, $word));
            })
            ->sortBy(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->map(fn (NavigationItem $item): GlobalSearchResult => new GlobalSearchResult(
                title: $item->getLabel(),
                url: $item->getUrl(),
                details: filled($item->getGroup()) ? [__('Group') => (string) $item->getGroup()] : [],
            ))
            ->values();

        return GlobalSearchResults::make()->category(__('Menu'), $results);
    }
}
