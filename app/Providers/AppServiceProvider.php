<?php

namespace App\Providers;

use App\Policies\ActivityPolicy;
use App\Support\AppSettings;
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
