<?php

namespace App\Filament\Support;

use App\Filament\Pages\DailyPanelReportPage;
use App\Filament\Pages\Jurnal;
use App\Filament\Pages\Reports\HitungBersih;
use App\Filament\Pages\Reports\LaporanTutupBuku;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\PayRuns\PayRunResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Illuminate\Support\Collection;

/**
 * The top menu: the pages H D uses every day in their usual groups (asked
 * 2026-11-09, plus Jurnal, where staff work), and every other page the user
 * may open under "Lainnya", so nothing is a dead end.
 */
class Menu
{
    /**
     * @var list<class-string>
     */
    public const MAIN = [
        Jurnal::class,
        DailyPanelReportPage::class,
        LaporanTutupBuku::class,
        HitungBersih::class,
        BrandResource::class,
        CompanyResource::class,
        CategoryResource::class,
        PayRunResource::class,
        EmployeeResource::class,
    ];

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $groupOrder = array_map(__(...), ['Jurnal', 'Laporan', 'Master Data', 'People', 'Settings']);

        $groups = self::itemsOf(self::MAIN)
            ->sortBy(fn (NavigationItem $item): int => $item->getSort())
            ->groupBy(fn (NavigationItem $item): string => (string) $item->getGroup())
            ->sortBy(fn (Collection $items, string $group): int => array_search($group, $groupOrder, true) ?: 0)
            ->map(fn (Collection $items, string $group): NavigationGroup => NavigationGroup::make($group)->items($items->values()->all()))
            ->values();

        $others = self::itemsOf(self::others())
            ->sortBy(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->map(fn (NavigationItem $item): NavigationItem => $item->group(__('More'))->sort(0))
            ->values();

        if ($others->isNotEmpty()) {
            $groups->push(NavigationGroup::make(__('More'))->items($others->all()));
        }

        return $builder->groups($groups->all());
    }

    /**
     * Every page and list the user may open, for the search box.
     *
     * @return Collection<int, NavigationItem>
     */
    public static function accessibleItems(): Collection
    {
        return self::itemsOf([...self::MAIN, ...self::others()]);
    }

    /**
     * @return list<class-string>
     */
    private static function others(): array
    {
        return collect([...Filament::getPages(), ...Filament::getResources()])
            ->reject(fn (string $class): bool => in_array($class, self::MAIN, true) || is_a($class, Dashboard::class, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, class-string>  $classes
     * @return Collection<int, NavigationItem>
     */
    private static function itemsOf(array $classes): Collection
    {
        return collect($classes)
            ->filter(fn (string $class): bool => $class::shouldRegisterNavigation() && $class::canAccess())
            ->flatMap(fn (string $class): array => $class::getNavigationItems())
            ->values();
    }
}
