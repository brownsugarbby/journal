<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Values for the STATUS column of office expenses.
 */
#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class ExpenseStatus extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
