<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Website / brand (KES, JALU...). Scopes accounts, panels, bonus types and members.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Brand extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CompanyAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(CompanyAccount::class);
    }

    /**
     * @return HasMany<GamePanel, $this>
     */
    public function gamePanels(): HasMany
    {
        return $this->hasMany(GamePanel::class);
    }

    /**
     * @return HasMany<BonusType, $this>
     */
    public function bonusTypes(): HasMany
    {
        return $this->hasMany(BonusType::class);
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
