<?php

namespace App\Models;

use App\Models\Concerns\LogsMasterDataActivity;
use App\Policies\MasterDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
#[UsePolicy(MasterDataPolicy::class)]
class Currency extends Model
{
    use LogsMasterDataActivity;

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'decimals' => 'integer',
        ];
    }

    public static function base(): self
    {
        return static::query()->where('is_base', true)->firstOrFail();
    }
}
