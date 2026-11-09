<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\DailyPanelReportPage;
use App\Filament\Pages\Jurnal;
use App\Filament\Pages\Pusat;
use App\Filament\Pages\Reports\HitungBersih;
use App\Filament\Pages\Reports\LaporanTutupBuku;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\PayRuns\PayRunResource;
use App\Support\AppSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName(fn (): string => app(AppSettings::class)->get('company_name'))
            ->brandLogo(fn (): ?string => ($logo = app(AppSettings::class)->get('company_logo'))
                ? Storage::disk('public')->url($logo)
                : null)
            ->colors([
                'primary' => self::primaryPalette(),
            ])
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => self::menu($builder))
            // Menu across the top (dropdown per group) so the wide sheets get the
            // whole screen; phones and small tablets get the usual menu drawer.
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('filament.entry-row-styles'))
            ->renderHook(
                TablesRenderHook::CONTENT_BEFORE,
                fn (array $data): View|string => ($account = $data['table']->getLivewire()->account())
                    ? view('filament.opening-balance-row', ['account' => $account])
                    : '',
                scopes: [Jurnal::class, Pusat::class],
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * The only pages in the menu, as H D asked (2026-11-09), for every role.
     * The other pages still exist and open by their link (/admin/jurnal,
     * /admin/users, /admin/settings…); add a class here to bring one back.
     *
     * @var list<class-string>
     */
    public const MENU = [
        DailyPanelReportPage::class,
        LaporanTutupBuku::class,
        HitungBersih::class,
        BrandResource::class,
        CompanyResource::class,
        CategoryResource::class,
        PayRunResource::class,
        EmployeeResource::class,
    ];

    /**
     * MENU in the usual groups, leaving out what the user may not open.
     */
    private static function menu(NavigationBuilder $builder): NavigationBuilder
    {
        $groupOrder = array_map(__(...), ['Jurnal', 'Laporan', 'Master Data', 'People', 'Settings']);

        $items = collect(self::MENU)
            ->filter(fn (string $class): bool => $class::canAccess())
            ->flatMap(fn (string $class): array => $class::getNavigationItems())
            ->sortBy(fn (NavigationItem $item): int => $item->getSort());

        return $builder->groups($items
            ->groupBy(fn (NavigationItem $item): string => (string) $item->getGroup())
            ->sortBy(fn (Collection $items, string $group): int => array_search($group, $groupOrder, true) ?: 0)
            ->map(fn (Collection $items, string $group): NavigationGroup => NavigationGroup::make($group)->items($items->values()->all()))
            ->values()
            ->all());
    }

    /**
     * Emerald shifted one shade darker. Filament puts white text on shade 600
     * only if it passes WCAG AA; stock emerald-600 doesn't, which gave light
     * green buttons with black text. Here 600 = emerald-700, so primary
     * buttons are dark green with white text.
     *
     * @return array<int, string>
     */
    private static function primaryPalette(): array
    {
        $e = Color::Emerald;

        return [
            50 => $e[50],
            100 => $e[100],
            200 => $e[200],
            300 => $e[300],
            400 => $e[400],
            500 => $e[600],
            600 => $e[700],
            700 => $e[800],
            800 => $e[900],
            900 => $e[950],
            950 => $e[950],
        ];
    }
}
