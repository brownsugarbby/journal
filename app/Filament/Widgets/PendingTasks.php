<?php

namespace App\Filament\Widgets;

use App\Enums\PayRunStatus;
use App\Enums\PeriodStatus;
use App\Filament\Pages\DailyPanelReportPage;
use App\Filament\Pages\TutupBuku;
use App\Filament\Resources\PayRuns\PayRunResource;
use App\Models\Brand;
use App\Models\CompanyAccount;
use App\Models\DailyPanelReport;
use App\Models\PayRun;
use App\Models\Period;
use App\Models\User;
use Filament\Widgets\Widget;

/**
 * Things to do, for superadmin and leader: panel figures not entered,
 * unpaid pay runs, accounts never checked, finished months still open.
 */
class PendingTasks extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.pending-tasks';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->hasFullAccess() ?? false;
    }

    /**
     * @return array<int, array{text: string, url: string|null}>
     */
    public function tasks(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $tasks = [];
        $yesterday = today()->subDay();

        foreach (Brand::query()->where('is_active', true)->orderBy('code')->get() as $brand) {
            $filled = DailyPanelReport::query()
                ->where('brand_id', $brand->id)
                ->whereDate('report_date', $yesterday)
                ->whereNotNull('from_dp')
                ->exists();

            if (! $filled) {
                $tasks[] = ['text' => "Daily Panel Report {$brand->code} for {$yesterday->format('d/m')} not entered", 'url' => DailyPanelReportPage::getUrl(['brand' => $brand->id, 'month' => $yesterday->format('Y-m')])];
            }
        }

        foreach (PayRun::query()->where('status', PayRunStatus::Draft)->orderBy('pay_date')->get() as $run) {
            $tasks[] = ['text' => "{$run->label()} not paid yet (pay date {$run->pay_date->format('d/m')})", 'url' => PayRunResource::getUrl('sheet', ['record' => $run])];
        }

        $neverChecked = CompanyAccount::query()->where('is_active', true)->whereDoesntHave('balanceChecks')->count();

        if ($neverChecked > 0) {
            $tasks[] = ['text' => "{$neverChecked} account(s) never checked with Cek Saldo", 'url' => null];
        }

        if ($user->canCloseBooks()) {
            Period::query()
                ->where('status', PeriodStatus::Open)
                ->get()
                ->filter(fn (Period $period): bool => now()->setDate($period->year, $period->month, 1)->endOfMonth()->isPast())
                ->each(function (Period $period) use (&$tasks): void {
                    $tasks[] = ['text' => "{$period->label()} has ended: tutup buku", 'url' => TutupBuku::getUrl()];
                });
        }

        return $tasks;
    }
}
