<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PT entity: employees and (optionally) accounts belong to one.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Company extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @return HasMany<CompanyAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(CompanyAccount::class);
    }
}
