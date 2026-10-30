<?php

namespace App\Services;

use App\Enums\PayRunStatus;
use App\Enums\PeriodStatus;
use App\Models\BalanceCheck;
use App\Models\Brand;
use App\Models\CompanyAccount;
use App\Models\DailyPanelReport;
use App\Models\JournalLine;
use App\Models\PayRun;
use App\Models\Period;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tutup buku: closing a month makes it read-only (no entry, edit, void or
 * payment dated in it) and snapshots every account's closing balance.
 * Superadmin only; reopening is logged and drops the snapshot.
 */
class PeriodService
{
    /**
     * @return array{blockers: array<int, string>, warnings: array<int, string>}
     */
    public function checks(Period $period): array
    {
        $blockers = [];
        $warnings = [];
        $end = now()->setDate($period->year, $period->month, 1)->endOfMonth();

        if ($end->isFuture()) {
            $blockers[] = __(':period has not ended yet.', ['period' => $period->label()]);
        }

        $accounts = CompanyAccount::query()->where('is_active', true)->orderBy('label')->get();
        $lastChecks = BalanceCheck::query()
            ->whereIn('id', BalanceCheck::query()->selectRaw('max(id)')->groupBy('company_account_id'))
            ->get()
            ->keyBy('company_account_id');

        foreach ($accounts as $account) {
            $check = $lastChecks->get($account->id);

            if ($check && ! $check->isMatching()) {
                $blockers[] = __(':account: last Cek Saldo has a selisih of :amount.', ['account' => $account->label, 'amount' => number_format((float) $check->difference, 2, ',', '.')]);
            } elseif (! $check) {
                $warnings[] = __(':account: never checked with Cek Saldo.', ['account' => $account->label]);
            }
        }

        foreach (PayRun::query()->where(['year' => $period->year, 'month' => $period->month, 'status' => PayRunStatus::Draft])->get() as $run) {
            $warnings[] = __(':run is still a draft (not paid).', ['run' => $run->label()]);
        }

        $days = min($end->day, (int) now()->setDate($period->year, $period->month, 1)->daysInMonth);

        foreach (Brand::query()->where('is_active', true)->get() as $brand) {
            $filled = DailyPanelReport::query()
                ->where('brand_id', $brand->id)
                ->whereYear('report_date', $period->year)
                ->whereMonth('report_date', $period->month)
                ->whereNotNull('from_dp')
                ->count();

            if ($filled < $days) {
                $warnings[] = __('Daily Panel Report :brand: :days day(s) without figures.', ['brand' => $brand->code, 'days' => $days - $filled]);
            }
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    public function close(Period $period, User $user): Period
    {
        if (! $user->canCloseBooks()) {
            throw ValidationException::withMessages(['period' => __('Only a superadmin can close the books.')]);
        }

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['period' => __(':period is already closed.', ['period' => $period->label()])]);
        }

        $blockers = $this->checks($period)['blockers'];

        if ($blockers !== []) {
            throw ValidationException::withMessages(['period' => $blockers]);
        }

        return DB::transaction(function () use ($period, $user): Period {
            $end = now()->setDate($period->year, $period->month, 1)->endOfMonth()->toDateString();
            $sums = JournalLine::query()
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journal_lines.ledgerable_type', (new CompanyAccount)->getMorphClass())
                ->whereDate('journals.entry_date', '<=', $end)
                ->groupBy('journal_lines.ledgerable_id')
                ->selectRaw('journal_lines.ledgerable_id as id, sum(journal_lines.amount) as total')
                ->pluck('total', 'id');

            foreach (CompanyAccount::query()->with('currency')->get() as $account) {
                $balance = bcadd((string) $account->opening_balance, (string) ($sums[$account->id] ?? '0'), 2);
                $kurs = $account->averageKurs();

                $period->balances()->updateOrCreate(['company_account_id' => $account->id], [
                    'balance' => $balance,
                    'kurs' => $kurs,
                    'idr_value' => $kurs === null ? null : bcmul($balance, $kurs, 2),
                ]);
            }

            $period->update(['status' => PeriodStatus::Closed, 'closed_by' => $user->id, 'closed_at' => now()]);

            activity('period')->performedOn($period)->causedBy($user)->event('closed')->log("Tutup buku {$period->label()}");

            return $period->refresh();
        });
    }

    public function reopen(Period $period, string $reason, User $user): Period
    {
        if (! $user->canCloseBooks()) {
            throw ValidationException::withMessages(['period' => __('Only a superadmin can reopen the books.')]);
        }

        return DB::transaction(function () use ($period, $reason, $user): Period {
            $period->balances()->delete();
            $period->update(['status' => PeriodStatus::Open, 'closed_by' => null, 'closed_at' => null]);

            activity('period')->performedOn($period)->causedBy($user)->event('reopened')
                ->withProperties(['reason' => $reason])
                ->log("Reopened {$period->label()}: {$reason}");

            return $period->refresh();
        });
    }
}
