<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Support\AppSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->navigationGroups([
                'Jurnal',
                'Laporan',
                'Master Data',
                'People',
                'Settings',
            ])
            ->sidebarCollapsibleOnDesktop()
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
