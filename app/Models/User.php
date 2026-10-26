<?php

namespace App\Models;

use App\Enums\AccountTier;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'is_active', 'can_enter_expense', 'can_enter_panel_report'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_enter_expense' => 'boolean',
            'can_enter_panel_report' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Brands an admin may post on (superadmin / leader see everything).
     *
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class);
    }

    /**
     * Operational accounts an admin may post on (superadmin / leader see everything).
     *
     * @return BelongsToMany<CompanyAccount, $this>
     */
    public function companyAccounts(): BelongsToMany
    {
        return $this->belongsToMany(CompanyAccount::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin->value);
    }

    public function isLeader(): bool
    {
        return $this->hasRole(Role::Leader->value);
    }

    /**
     * Every menu and every brand / account: superadmin and leader.
     */
    public function hasFullAccess(): bool
    {
        return $this->hasAnyRole([Role::SuperAdmin->value, Role::Leader->value]);
    }

    /**
     * Closing / reopening a month (tutup buku): superadmin only.
     */
    public function canCloseBooks(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * The profit calculation (Hitung Bersih / monthly P&L): superadmin only.
     */
    public function canViewProfit(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * Staff CS only enter DP and WD: no bonus, cashback, panel report,
     * expenses or saldo overview, whatever toggles are set.
     */
    public function isStaffCs(): bool
    {
        return $this->hasRole(Role::StaffCs->value);
    }

    public function role(): ?Role
    {
        return Role::tryFrom($this->roles->first()?->name ?? '');
    }

    /**
     * Operational accounts this user may post DP/WD on.
     *
     * @return Builder<CompanyAccount>
     */
    public function accessibleAccounts(): Builder
    {
        return CompanyAccount::query()
            ->where('is_active', true)
            ->where('tier', AccountTier::Operational)
            ->when(! $this->hasFullAccess(), fn (Builder $query) => $query->whereIn(
                'id',
                $this->companyAccounts()->select('company_accounts.id'),
            ))
            ->orderBy('label');
    }

    /**
     * Brands this user may post bonus / cashback / panel reports on.
     *
     * @return Builder<Brand>
     */
    public function accessibleBrands(): Builder
    {
        return Brand::query()
            ->where('is_active', true)
            ->when(! $this->hasFullAccess(), fn (Builder $query) => $query->whereIn(
                'id',
                $this->brands()->select('brands.id'),
            ))
            ->orderBy('code');
    }

    public function canPostOn(CompanyAccount $account): bool
    {
        return $this->accessibleAccounts()->whereKey($account->getKey())->exists();
    }

    public function canPostForBrand(Brand $brand): bool
    {
        return $this->accessibleBrands()->whereKey($brand->getKey())->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Password is never logged; password resets are logged separately by the user form.
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'username', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
