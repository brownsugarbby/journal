<?php

namespace App\Services;

use App\Enums\AdjustmentDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\ExpenseDirection;
use App\Enums\JournalType;
use App\Enums\LoanKind;
use App\Enums\PartnerKind;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyAccount;
use App\Models\ExpenseStatus;
use App\Models\Journal;
use App\Models\LoanCounterparty;
use App\Models\Partner;
use App\Models\User;
use BackedEnum;
use Illuminate\Validation\ValidationException;

/**
 * Validation and ledger lines for the central-money journals, used by
 * JournalService. Lines are [ledgerable, amount in its currency, IDR value].
 *
 * Money leaving a THB/USD account is valued at that account's average kurs,
 * so the P&L sees the IDR the money actually cost.
 *
 *   Transfer      from −(X+fee)          to +X           Biaya ADM +fee
 *   Beli valas    from (IDR) −IDR paid   to (THB/USD) +units, valued at IDR paid
 *   Pengeluaran   account −X             category +X     (MASUK = refund: reversed)
 *   Pinjaman      account ±X             counterparty ∓X (interest: Bunga +X)
 *   Partner       account ±X             partner ∓X
 *   Penyesuaian   account ±X             Penyesuaian Saldo ∓X
 */
class CentralMoneyEntries
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(JournalType $type, array $data, User $user): array
    {
        if ($type !== JournalType::Expense && ! $user->hasFullAccess()) {
            throw ValidationException::withMessages(['company_account_id' => 'Only a superadmin or leader can enter this.']);
        }

        return [
            // Clear member fields so an entry never carries stale values.
            'member_id' => null,
            'member_bank_id' => null,
            'member_account_name' => null,
            'bonus_type_id' => null,
            'winlose' => null,
            'percentage' => null,
            'is_manual' => false,
            'target_account_id' => null,
            'category_id' => null,
            'expense_status_id' => null,
            'loan_counterparty_id' => null,
            'partner_id' => null,
            'brand_id' => null,
            'company_id' => null,
            'kind' => null,
            'fee' => '0.00',
            'foreign_amount' => null,
            'kurs' => null,
            ...match ($type) {
                JournalType::Transfer => $this->transfer($data),
                JournalType::CurrencyPurchase => $this->currencyPurchase($data),
                JournalType::Expense => $this->expense($data, $user),
                JournalType::Loan => $this->loan($data),
                JournalType::Partner => $this->partner($data),
                JournalType::Adjustment => $this->adjustment($data),
            },
        ];
    }

    /**
     * @return array<int, array{0: mixed, 1: string, 2: string}>
     */
    public function lines(Journal $journal): array
    {
        $account = $journal->companyAccount;
        $amount = (string) $journal->amount;

        return match ($journal->type) {
            JournalType::Transfer => $this->transferLines($journal),
            JournalType::CurrencyPurchase => [
                [$account, $this->neg($amount), $this->neg($amount)],
                [$journal->targetAccount, (string) $journal->foreign_amount, $amount],
            ],
            JournalType::Expense => $this->pair($account, $journal->kindEnum()->sign(), $amount, $journal->category),
            JournalType::Loan => $this->pair(
                $account,
                $journal->kindEnum()->sign(),
                $amount,
                $journal->kindEnum() === LoanKind::InterestPaid ? $this->category('loan.interest') : $journal->loanCounterparty,
            ),
            JournalType::Partner => $this->pair($account, $journal->kindEnum()->sign(), $amount, $journal->partner),
            JournalType::Adjustment => $this->pair($account, $journal->kindEnum()->sign(), $amount, $this->category('journal.adjustment')),
        };
    }

    /**
     * Money on the account in its currency, the opposite IDR value on the other side.
     *
     * @return array<int, array{0: mixed, 1: string, 2: string}>
     */
    private function pair(CompanyAccount $account, int $sign, string $amount, mixed $other): array
    {
        $value = $sign > 0 ? $amount : $this->neg($amount);
        $idr = $this->idr($account, $value);

        return [
            [$account, $value, $idr],
            [$other, $this->neg($idr), $this->neg($idr)],
        ];
    }

    /**
     * @return array<int, array{0: mixed, 1: string, 2: string}>
     */
    private function transferLines(Journal $journal): array
    {
        $from = $journal->companyAccount;
        $amount = (string) $journal->amount;
        $fee = (string) $journal->fee;
        $out = $this->neg(bcadd($amount, $fee, 2));
        $outIdr = $this->idr($from, $out);
        $feeIdr = $this->idr($from, $fee);

        return [
            [$from, $out, $outIdr],
            [$journal->targetAccount, $amount, bcsub($this->neg($outIdr), $feeIdr, 2)],
            [$this->category('biaya_adm', byCode: true), $feeIdr, $feeIdr],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function transfer(array $data): array
    {
        $from = $this->account($data['company_account_id'] ?? null, 'company_account_id');
        $to = $this->account($data['target_account_id'] ?? null, 'target_account_id');

        if ($from->is($to)) {
            throw ValidationException::withMessages(['target_account_id' => 'Pick a different account.']);
        }

        if ($from->currency_id !== $to->currency_id) {
            throw ValidationException::withMessages(['target_account_id' => 'Both accounts must use the same currency. Use Beli valas to change currency.']);
        }

        $this->requireKurs($from);

        return [
            'company_account_id' => $from->id,
            'target_account_id' => $to->id,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
            'fee' => $this->money($data['fee'] ?? 0, 'fee', allowZero: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function currencyPurchase(array $data): array
    {
        $from = $this->account($data['company_account_id'] ?? null, 'company_account_id');
        $to = $this->account($data['target_account_id'] ?? null, 'target_account_id');

        if (! $from->isBaseCurrency()) {
            throw ValidationException::withMessages(['company_account_id' => 'Pay from a rupiah account.']);
        }

        if ($to->isBaseCurrency()) {
            throw ValidationException::withMessages(['target_account_id' => 'Pick a THB / USD account.']);
        }

        $units = $this->money($data['foreign_amount'] ?? null, 'foreign_amount');
        $kurs = $this->money($data['kurs'] ?? null, 'kurs', scale: 6);
        $paid = filled($data['amount'] ?? null)
            ? $this->money($data['amount'], 'amount')
            : bcmul($units, $kurs, 2);

        return [
            'company_account_id' => $from->id,
            'target_account_id' => $to->id,
            'foreign_amount' => $units,
            'kurs' => $kurs,
            'amount' => $paid,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function expense(array $data, User $user): array
    {
        $account = $this->account($data['company_account_id'] ?? null, 'company_account_id');

        if (! $user->hasFullAccess() && (! $user->can_enter_expense || $user->isStaffCs() || ! $user->canPostOn($account))) {
            throw ValidationException::withMessages(['company_account_id' => 'You cannot pay expenses from this account.']);
        }

        $category = Category::query()
            ->where('is_active', true)
            ->where('source', CategorySource::Manual)
            ->where('type', CategoryType::Expense)
            ->find($data['category_id'] ?? null);

        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Pick an expense category.']);
        }

        $status = ExpenseStatus::query()->where('is_active', true)->find($data['expense_status_id'] ?? null);

        if (! $status) {
            throw ValidationException::withMessages(['expense_status_id' => 'Pick a status.']);
        }

        $direction = $this->enum(ExpenseDirection::class, $data['kind'] ?? null, 'kind');

        if ($direction === ExpenseDirection::Keluar) {
            $this->requireKurs($account);
        }

        return [
            'company_account_id' => $account->id,
            'category_id' => $category->id,
            'expense_status_id' => $status->id,
            'brand_id' => filled($data['brand_id'] ?? null) ? Brand::query()->findOrFail($data['brand_id'])->id : null,
            'company_id' => filled($data['company_id'] ?? null) ? Company::query()->findOrFail($data['company_id'])->id : null,
            'kind' => $direction->value,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function loan(array $data): array
    {
        $account = $this->account($data['company_account_id'] ?? null, 'company_account_id');
        $counterparty = LoanCounterparty::query()->where('is_active', true)->find($data['loan_counterparty_id'] ?? null);

        if (! $counterparty) {
            throw ValidationException::withMessages(['loan_counterparty_id' => 'Pick who the loan is with.']);
        }

        $kind = $this->enum(LoanKind::class, $data['kind'] ?? null, 'kind');

        if ($kind->sign() < 0) {
            $this->requireKurs($account);
        }

        return [
            'company_account_id' => $account->id,
            'loan_counterparty_id' => $counterparty->id,
            'kind' => $kind->value,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function partner(array $data): array
    {
        $account = $this->account($data['company_account_id'] ?? null, 'company_account_id');
        $partner = Partner::query()->where('is_active', true)->find($data['partner_id'] ?? null);

        if (! $partner) {
            throw ValidationException::withMessages(['partner_id' => 'Pick a partner.']);
        }

        $kind = $this->enum(PartnerKind::class, $data['kind'] ?? null, 'kind');
        $this->requireKurs($account);

        return [
            'company_account_id' => $account->id,
            'partner_id' => $partner->id,
            'kind' => $kind->value,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function adjustment(array $data): array
    {
        $account = $this->account($data['company_account_id'] ?? null, 'company_account_id');

        if (blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Say why the balance is adjusted.']);
        }

        $this->requireKurs($account);

        return [
            'company_account_id' => $account->id,
            'kind' => $this->enum(AdjustmentDirection::class, $data['kind'] ?? null, 'kind')->value,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
        ];
    }

    private function account(mixed $id, string $field): CompanyAccount
    {
        $account = CompanyAccount::query()->with('currency')->where('is_active', true)->find($id);

        if (! $account) {
            throw ValidationException::withMessages([$field => 'Pick an account.']);
        }

        return $account;
    }

    /**
     * Money leaving a THB/USD account needs a kurs to be valued in IDR.
     */
    private function requireKurs(CompanyAccount $account): void
    {
        if ($account->averageKurs() === null) {
            throw ValidationException::withMessages([
                'company_account_id' => "{$account->label} has no kurs yet: buy {$account->currency->code} first or set its opening kurs.",
            ]);
        }
    }

    private function idr(CompanyAccount $account, string $value): string
    {
        return $account->isBaseCurrency() ? $value : bcmul($value, (string) $account->averageKurs(), 2);
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $enum, mixed $value, string $field): BackedEnum
    {
        $case = $value instanceof $enum ? $value : $enum::tryFrom((string) $value);

        if (! $case) {
            throw ValidationException::withMessages([$field => 'Pick a type.']);
        }

        return $case;
    }

    private function money(mixed $value, string $field, bool $allowZero = false, int $scale = 2): string
    {
        $normalized = is_string($value) ? trim($value) : $value;

        $rounded = is_numeric($normalized) ? number_format((float) $normalized, $scale, '.', '') : null;

        // Compare the rounded value: 0.001 must not slip through as 0.00.
        if ($rounded === null || ($allowZero ? bccomp($rounded, '0', $scale) < 0 : bccomp($rounded, '0', $scale) <= 0)) {
            throw ValidationException::withMessages([$field => $allowZero ? 'Must be 0 or more.' : 'Must be more than 0.']);
        }

        return $rounded;
    }

    private function category(string $key, bool $byCode = false): Category
    {
        return Category::query()->where($byCode ? 'code' : 'source_key', $key)->firstOrFail();
    }

    private function neg(string $value): string
    {
        return bcmul($value, '-1', 2);
    }
}
