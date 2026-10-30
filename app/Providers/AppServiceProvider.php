<?php

namespace App\Providers;

use App\Policies\ActivityPolicy;
use App\Support\AppSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Labels are written in English and translated by lang/id.json, so the
        // whole UI follows APP_LOCALE (Filament's own texts already do).
        foreach ([Field::class, Column::class, ColumnGroup::class, BaseFilter::class, Entry::class, Action::class, Tab::class, Fieldset::class] as $component) {
            $component::configureUsing(fn ($component) => $component->translateLabel());
        }

        // Embed only the glyphs used: payslips / laporan go from ~880 KB to a few KB.
        config(['dompdf.options.enable_font_subsetting' => true]);

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        // Behind Cloudflare / a load balancer, so audit logs record the real client IP.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
