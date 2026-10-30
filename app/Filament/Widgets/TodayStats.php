<?php

namespace App\Filament\Widgets;

use App\Enums\JournalType;
use App\Filament\Support\Fields;
use App\Models\Journal;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today at a glance. Staff see their own accounts and brands; superadmin
 * and leader see everything.
 */
class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Hari ini';

    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $base = Journal::query()
            ->whereNull('voided_at')
            ->whereNull('reversal_of_id')
            ->whereDate('entry_date', today())
            ->when(! $user->hasFullAccess(), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('company_account_id', $user->accessibleAccounts()->select('company_accounts.id'))
                ->orWhereIn('brand_id', $user->accessibleBrands()->select('brands.id'))));

        $totals = (clone $base)
            ->selectRaw('sum(case when type = ? then amount else 0 end) as dp, sum(case when type = ? then 1 else 0 end) as dp_count', [JournalType::Deposit->value, JournalType::Deposit->value])
            ->selectRaw('sum(case when type = ? then amount else 0 end) as wd, sum(case when type = ? then 1 else 0 end) as wd_count', [JournalType::Withdraw->value, JournalType::Withdraw->value])
            ->selectRaw('sum(case when type in (?, ?) then amount else 0 end) as bonus', [JournalType::Bonus->value, JournalType::Cashback->value])
            ->toBase()
            ->first();

        $dp = (string) ($totals->dp ?? 0);
        $wd = (string) ($totals->wd ?? 0);
        $net = bcsub($dp, $wd, 2);

        $stats = [
            Stat::make(__('DP'), Fields::rupiah($dp))->description(($totals->dp_count ?? 0).' entries')->color('success'),
            Stat::make(__('WD'), Fields::rupiah($wd))->description(($totals->wd_count ?? 0).' entries')->color('danger'),
            Stat::make(__('DP − WD'), Fields::rupiah($net))->color(bccomp($net, '0', 2) < 0 ? 'danger' : 'success'),
        ];

        if (! $user->isStaffCs()) {
            $stats[] = Stat::make(__('Bonus + cashback'), Fields::rupiah($totals->bonus ?? 0))->color('warning');
        }

        if (! $user->hasFullAccess()) {
            $stats[] = Stat::make(__('My entries today'), (string) (clone $base)->where('created_by', $user->id)->count());
        }

        return $stats;
    }
}
