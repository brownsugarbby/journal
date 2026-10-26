<?php

namespace App\Models;

use App\Enums\BankType;
use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Banks and e-wallets: company bank accounts, member BANK dropdown, employee bank.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Bank extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'type' => BankType::class,
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
}
