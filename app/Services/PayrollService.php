<?php

namespace App\Services;

use App\Enums\AccountTier;
use App\Enums\JournalType;
use App\Enums\PayColumnKind;
use App\Enums\PayRunStatus;
use App\Enums\PayType;
use App\Models\Category;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LoanCounterparty;
use App\Models\PayColumn;
use App\Models\PayItem;
use App\Models\PayItemValue;
use App\Models\PayRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gaji and UM pay runs: build the sheet from the employee list, total it per
 * currency with TOTAL ALL in Rp, and post the payment.
 *
 * Paying posts one journal per currency, from the account chosen for it:
 *   account          −SISA total         (valued at the account's average kurs)
 *   Gaji / UM        +cost not on a column's own line
 *   column category  ±column total       (e.g. Lembur Offday)
 *   employee loan    −loan repayment     (they owe us less)
 */
class PayrollService
{
    public function __construct(private JournalService $journals) {}

    public function create(PayType $type, int $year, int $month, string $payDate, User $user): PayRun
    {
        $this->authorize($user);

        if (PayRun::query()->where(['pay_type' => $type, 'year' => $year, 'month' => $month])->exists()) {
            throw ValidationException::withMessages(['month' => __(':type for this month already exists.', ['type' => $type->getLabel()])]);
        }

        return DB::transaction(function () use ($type, $year, $month, $payDate, $user): PayRun {
            $run = PayRun::query()->create([
                'pay_type' => $type,
                'year' => $year,
                'month' => $month,
                'pay_date' => $payDate,
                'status' => PayRunStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->syncEmployees($run);

            return $run;
        });
    }

    /**
     * Add a row for every active employee with an amount for this pay type
     * who isn't on the sheet yet. Existing rows are never touched.
     */
    public function syncEmployees(PayRun $run): int
    {
        $this->ensureDraft($run);

        [$amountColumn, $currencyColumn] = $run->pay_type === PayType::Gaji
            ? ['salary_amount', 'salary_currency_id']
            : ['um_amount', 'um_currency_id'];

        $employees = Employee::query()
            ->where('is_active', true)
            ->where($amountColumn, '>', 0)
            ->whereNotIn('id', $run->items()->select('employee_id'))
            ->get();

        foreach ($employees as $employee) {
            $run->items()->create([
                'employee_id' => $employee->id,
                'currency_id' => $employee->{$currencyColumn},
                'gross' => $employee->{$amountColumn},
            ]);
        }

        return $employees->count();
    }

    public function setColumnValue(PayItem $item, int $columnId, mixed $value): void
    {
        $this->ensureDraft($item->payRun);
        $column = PayColumn::query()->where('pay_type', $item->payRun->pay_type)->findOrFail($columnId);

        $attributes = $column->kind === PayColumnKind::Info && ! is_numeric($value)
            ? ['text' => filled($value) ? (string) $value : null, 'value' => null]
            : ['value' => filled($value) ? $this->money($value, 'value') : null, 'text' => null];

        PayItemValue::query()->updateOrCreate(['pay_item_id' => $item->id, 'pay_column_id' => $column->id], $attributes);
    }

    /**
     * Totals per currency, with TOTAL ALL in Rp. A paid run uses the kurs it
     * was paid at; a draft shows the kurs it would be paid at today.
     *
     * @param  array<int, int|null>  $accounts  paying account per currency id
     * @return array{currencies: array<int, array<string, mixed>>, total_all: string|null}
     */
    public function totals(PayRun $run, array $accounts = []): array
    {
        $items = $this->items($run);
        $currencies = [];
        $totalAll = '0';
        $complete = true;

        foreach ($items->groupBy('currency_id') as $currencyId => $group) {
            $currency = $group->first()->currency;
            $kurs = $run->isPaid()
                ? ($run->kurs[$currency->code] ?? null)
                : $this->kursFor($currency, $accounts[$currencyId] ?? null);

            $sum = fn (callable $value): string => $group->reduce(fn (string $carry, PayItem $item) => bcadd($carry, (string) $value($item), 2), '0');
            $sisa = $sum(fn (PayItem $item) => $item->sisa());
            $idr = $kurs === null ? null : bcmul($sisa, (string) $kurs, 2);

            $currencies[$currencyId] = [
                'code' => $currency->code,
                'is_base' => $currency->is_base,
                'decimals' => $currency->decimals,
                'count' => $group->count(),
                'gross' => $sum(fn (PayItem $item) => $item->gross),
                'potong' => $sum(fn (PayItem $item) => $item->potong),
                'loan_repayment' => $sum(fn (PayItem $item) => $item->loan_repayment),
                'cost' => $sum(fn (PayItem $item) => $item->cost()),
                'sisa' => $sisa,
                'kurs' => $kurs,
                'idr' => $idr,
            ];

            $complete = $complete && $idr !== null;
            $totalAll = bcadd($totalAll, $idr ?? '0', 2);
        }

        uasort($currencies, fn (array $a, array $b) => $b['is_base'] <=> $a['is_base']);

        return ['currencies' => $currencies, 'total_all' => $complete ? $totalAll : null];
    }

    /**
     * The account a currency is normally paid from: the first active pusat or
     * cash account in that currency (e.g. CASH THB for baht).
     */
    public function defaultAccount(Currency $currency): ?CompanyAccount
    {
        return CompanyAccount::query()
            ->where('is_active', true)
            ->where('tier', AccountTier::Pusat)
            ->where('currency_id', $currency->id)
            ->orderBy('label')
            ->first();
    }

    /**
     * @param  array<int, int|string>  $accounts  paying account id per currency id
     */
    public function pay(PayRun $run, array $accounts, User $user, ?string $payDate = null): PayRun
    {
        $this->authorize($user);
        $this->ensureDraft($run);

        $items = $this->items($run)->filter(fn (PayItem $item): bool => bccomp($item->cost(), '0', 2) !== 0 || bccomp((string) $item->loan_repayment, '0', 2) !== 0);

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['accounts' => __('Nothing to pay on this sheet.')]);
        }

        $date = $payDate ?? $run->pay_date->toDateString();

        if (Carbon::parse($date)->isFuture()) {
            throw ValidationException::withMessages(['pay_date' => __('Pay date cannot be in the future.')]);
        }

        return DB::transaction(function () use ($run, $accounts, $user, $items, $date): PayRun {
            $kurs = [];

            foreach ($items->groupBy('currency_id') as $currencyId => $group) {
                $account = CompanyAccount::query()->with('currency')->where('is_active', true)->find($accounts[$currencyId] ?? null);
                $code = $group->first()->currency->code;

                if (! $account || $account->currency_id !== (int) $currencyId) {
                    throw ValidationException::withMessages(["accounts.{$currencyId}" => __('Pick a :currency account to pay from.', ['currency' => $code])]);
                }

                $rate = $account->averageKurs();

                if ($rate === null) {
                    throw ValidationException::withMessages(["accounts.{$currencyId}" => __(':account has no kurs yet: buy :currency first.', ['account' => $account->label, 'currency' => $code])]);
                }

                $this->journals->postPrepared(JournalType::Payroll, [
                    'entry_date' => $date,
                    'company_account_id' => $account->id,
                    'pay_run_id' => $run->id,
                    'kind' => $run->pay_type->value,
                    'amount' => $group->reduce(fn (string $sum, PayItem $item) => bcadd($sum, $item->sisa(), 2), '0'),
                    'note' => "{$code} · {$group->count()} org",
                ], $this->paymentLines($run, $account, $rate, $group), $user);

                $kurs[$code] = $rate;
            }

            $run->update([
                'status' => PayRunStatus::Paid,
                'pay_date' => $date,
                'kurs' => $kurs,
                'paid_by' => $user->id,
                'paid_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    /**
     * Void the payment journals and reopen the sheet for editing.
     */
    public function unpay(PayRun $run, string $reason, User $user): PayRun
    {
        $this->authorize($user);

        if (! $run->isPaid()) {
            throw ValidationException::withMessages(['reason' => __('This sheet is not paid.')]);
        }

        return DB::transaction(function () use ($run, $reason, $user): PayRun {
            foreach ($run->journals()->whereNull('voided_at')->whereNull('reversal_of_id')->get() as $journal) {
                $this->journals->void($journal, $reason, $user);
            }

            $run->update(['status' => PayRunStatus::Draft, 'kurs' => null, 'paid_by' => null, 'paid_at' => null]);

            return $run->refresh();
        });
    }

    /**
     * @param  Collection<int, PayItem>  $items
     * @return array<int, array{0: mixed, 1: string, 2: string}>
     */
    private function paymentLines(PayRun $run, CompanyAccount $account, string $rate, Collection $items): array
    {
        $idr = fn (string $units): string => bcmul($units, $rate, 2);
        $main = Category::query()->where('source_key', $run->pay_type === PayType::Gaji ? 'payroll.gaji' : 'payroll.um')->firstOrFail();
        $mainUnits = '0';
        $byCategory = [];
        $lines = [];

        foreach ($items as $item) {
            $itemMain = bcsub((string) $item->gross, (string) $item->potong, 2);

            foreach ($item->values as $value) {
                $column = $value->payColumn;

                if ($column->kind === PayColumnKind::Info || $value->value === null) {
                    continue;
                }

                $signed = $column->kind === PayColumnKind::Addition ? (string) $value->value : bcmul((string) $value->value, '-1', 2);

                if ($column->category_id) {
                    $byCategory[$column->category_id] = bcadd($byCategory[$column->category_id] ?? '0', $signed, 2);
                } else {
                    $itemMain = bcadd($itemMain, $signed, 2);
                }
            }

            $mainUnits = bcadd($mainUnits, $itemMain, 2);

            if (bccomp((string) $item->loan_repayment, '0', 2) === 1) {
                $counterparty = LoanCounterparty::query()->where('employee_id', $item->employee_id)->first();

                if (! $counterparty) {
                    throw ValidationException::withMessages(['accounts' => __(':employee has a loan repayment but no loan counterparty.', ['employee' => $item->employee->name])]);
                }

                $repaid = $idr((string) $item->loan_repayment);
                $lines[] = [$counterparty, bcmul($repaid, '-1', 2), bcmul($repaid, '-1', 2)];
            }
        }

        $lines[] = [$main, $idr($mainUnits), $idr($mainUnits)];

        foreach ($byCategory as $categoryId => $units) {
            $lines[] = [Category::query()->findOrFail($categoryId), $idr($units), $idr($units)];
        }

        // The account takes exactly the opposite, so the journal balances in Rp.
        $otherIdr = array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, $line[2], 2), '0');
        $paidOut = $items->reduce(fn (string $sum, PayItem $item) => bcadd($sum, $item->sisa(), 2), '0');
        $lines[] = [$account, bcmul($paidOut, '-1', 2), bcmul($otherIdr, '-1', 2)];

        return $lines;
    }

    /**
     * @return Collection<int, PayItem>
     */
    private function items(PayRun $run): Collection
    {
        return $run->items()->with(['currency', 'employee', 'values.payColumn'])->get();
    }

    private function kursFor(Currency $currency, ?int $accountId): ?string
    {
        if ($currency->is_base) {
            return '1';
        }

        $account = $accountId ? CompanyAccount::query()->find($accountId) : $this->defaultAccount($currency);

        return $account?->averageKurs();
    }

    private function ensureDraft(PayRun $run): void
    {
        if ($run->isPaid()) {
            throw ValidationException::withMessages(['status' => __('This sheet is paid. Unpay it first to change it.')]);
        }
    }

    private function authorize(User $user): void
    {
        if (! $user->hasFullAccess()) {
            throw ValidationException::withMessages(['status' => __('Only a superadmin or leader can run payroll.')]);
        }
    }

    private function money(mixed $value, string $field): string
    {
        $normalized = is_string($value) ? trim($value) : $value;

        if (! is_numeric($normalized) || $normalized < 0) {
            throw ValidationException::withMessages([$field => __('Must be 0 or more.')]);
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
