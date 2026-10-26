<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Key-value app settings stored in the `settings` table, cached forever and
 * flushed on save. Defaults below are used until a superadmin saves the page.
 */
class AppSettings
{
    public const CACHE_KEY = 'app_settings';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'company_name' => 'Journal System',
        'company_logo' => null,
        'staff_edit_window_minutes' => 10,
        'default_max_balance_operational' => 1_000_000,
        'default_max_balance_pusat' => 50_000_000,
        'pdf_header' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('settings')) {
                return self::DEFAULTS;
            }

            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
