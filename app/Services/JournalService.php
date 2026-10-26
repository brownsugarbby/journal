<?php

namespace App\Services;

use App\Enums\AccountTier;
use App\Enums\BonusKind;
use App\Enums\JournalType;
use App\Models\BalanceCheck;
use App\Models\BonusType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Period;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only place journals and their lines are written.
 *
 * Lines are signed, positive = debit. For a DP of MASUK with POTONGAN:
 *   account          +(MASUK − POTONGAN)   (SISA DP, what lands on the account)
 *   Potongan DP PGA  +POTONGAN
 *   Pendapatan Kotor −MASUK
 * For a WD of KELUAR with POTONGAN:
 *   account          −KELUAR
 *   Pendapatan Kotor +(KELUAR − POTONGAN)  (SISA WD, what the member gets)
 *   Potongan WD PGA  +POTONGAN
 * Bonus / cashback move no money (coin is given in the panel): one P&L line.
 *
 * Validation errors are keyed by input name so forms can show them inline.
 */
class JournalService
{
    /**
     * @param  array<string, mixed>  $data  entry_date, company_account_id, brand_id, member_id,
     *                                      member_bank_id, member_account_name, amount, fee,
     *                                      bonus_type_id, winlose, percentage, is_manual, note
     */
    public function post(JournalType $type, array $data, User $user): Journal
    {
        return DB::transaction(function () use ($type, $data, $user): Journal {
            $attributes = $this->resolve($type, $data, $user);
            $period = $this->openPeriodFor($attributes['entry_date']);

            $journal = Journal::query()->create([
                ...$attributes,
                'type' => $type,
                'number' => $this->nextNumber($type, $period),
                'period_id' => $period->id,
                'created_by' => $user->id,
                'created_ip' => request()->ip(),
            ]);

            $this->writeLines($journal);

            return $journal->refresh();
        });
    }

    /**
     * Post a journal whose lines were built by another service (payroll).
     * Same rules as post(): open period, numbering, balanced in IDR.
     *
     * @param  array<string, mixed>  $attributes  must include entry_date
     * @param  array<int, array{0: mixed, 1: string, 2: string}>  $lines  [ledgerable, amount, IDR value]
     */
    public function postPrepared(JournalType $type, array $attributes, array $lines, User $user): Journal
    {
        return DB::transaction(function () use ($type, $attributes, $lines, $user): Journal {
            $period = $this->openPeriodFor($attributes['entry_date']);

            $journal = Journal::query()->create([
                ...$attributes,
                'type' => $type,
                'number' => $this->nextNumber($type, $period),
                'period_id' => $period->id,
                'created_by' => $user->id,
                'created_ip' => request()->ip(),
            ]);

            $this->storeLines($journal, $lines);

            return $journal->refresh();
        });
    }

    /**
     * Edit inside the edit window: same type, new values, lines rebuilt.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Journal $journal, array $data, User $user): Journal
    {
        // Reload for the same reason as void(): no computed table columns.
        $journal = Journal::query()->findOrFail($journal->getKey());

        if (! $user->can('update', $journal)) {
            throw ValidationException::withMessages(['amount' => 'This entry can no longer be edited.']);
        }

        return DB::transaction(function () use ($journal, $data, $user): Journal {
            $attributes = $this->resolve($journal->type, [
                'company_account_id' => $journal->company_account_id,
                'brand_id' => $journal->brand_id,
                ...$data,
            ], $user);
            $period = $this->openPeriodFor($attributes['entry_date']);

            $journal->update([...$attributes, 'period_id' => $period->id]);
            $journal->lines()->delete();
            $this->writeLines($journal);

            return $journal->refresh();
        });
    }

    /**
     * Mark the journal void and post a reversal with the opposite lines on the
     * same date, so balances and daily totals net back to zero.
     */
    public function void(Journal $journal, string $reason, User $user): Journal
    {
        // Reload: a table row may carry computed columns (running totals)
        // that must not be copied into the reversal.
        $journal = Journal::query()->with('lines')->findOrFail($journal->getKey());

        if (! $user->can('void', $journal)) {
            throw ValidationException::withMessages(['reason' => 'You cannot void this entry.']);
        }

        return DB::transaction(function () use ($journal, $reason, $user): Journal {
            $journal->update([
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            $reversal = $journal->replicate(['number', 'voided_at', 'voided_by', 'void_reason', 'created_by', 'created_ip']);
            $reversal->fill([
                'number' => $journal->number.'-R',
                'reversal_of_id' => $journal->id,
                'note' => "VOID {$journal->number}: {$reason}",
                'created_by' => $user->id,
                'created_ip' => request()->ip(),
            ])->save();

            foreach ($journal->lines as $line) {
                $reversal->lines()->create([
                    'ledgerable_type' => $line->ledgerable_type,
                    'ledgerable_id' => $line->ledgerable_id,
                    'currency_id' => $line->currency_id,
                    'amount' => bcmul((string) $line->amount, '-1', 2),
                    'idr_amount' => bcmul((string) $line->idr_amount, '-1', 2),
                ]);
            }

            return $reversal;
        });
    }

    public function checkBalance(CompanyAccount $account, string|int|float $actual, ?string $note, User $user): BalanceCheck
    {
        if (! $user->hasFullAccess() && ! $user->canPostOn($account)) {
            throw ValidationException::withMessages(['actual_balance' => 'You are not assigned to this account.']);
        }

        $book = $account->balance();
        $actual = number_format((float) $actual, 2, '.', '');

        return $account->balanceChecks()->create([
            'actual_balance' => $actual,
            'book_balance' => $book,
            'difference' => bcsub($actual, $book, 2),
            'note' => $note,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Validate input and turn it into journal attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolve(JournalType $type, array $data, User $user): array
    {
        $entryDate = Carbon::parse($data['entry_date'] ?? today())->startOfDay();

        if ($entryDate->isFuture()) {
            throw ValidationException::withMessages(['entry_date' => 'TGL cannot be in the future.']);
        }

        if (! in_array($type, [JournalType::Deposit, JournalType::Withdraw, JournalType::Bonus, JournalType::Cashback], true)) {
            return [
                'entry_date' => $entryDate->toDateString(),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                ...$this->centralMoney()->resolve($type, $data, $user),
            ];
        }

        $memberId = trim((string) ($data['member_id'] ?? ''));

        if ($memberId === '') {
            throw ValidationException::withMessages(['member_id' => 'USERNAME (member ID) is required.']);
        }

        $attributes = [
            'entry_date' => $entryDate->toDateString(),
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'is_manual' => (bool) ($data['is_manual'] ?? false),
            'member_bank_id' => $data['member_bank_id'] ?? null,
            'member_account_name' => filled($data['member_account_name'] ?? null) ? trim($data['member_account_name']) : null,
        ];

        if ($type->movesMoney()) {
            $attributes += $this->resolveMoney($data, $user);
        } else {
            $attributes += $this->resolveBonus($type, $data, $user);
        }

        $brand = Brand::query()->findOrFail($attributes['brand_id']);
        $attributes['member_id'] = $this->member($brand, $memberId, $attributes)->id;

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveMoney(array $data, User $user): array
    {
        $account = CompanyAccount::query()->with('paymentGateway')->find($data['company_account_id'] ?? null);

        if (! $account || $account->tier !== AccountTier::Operational || ! $user->canPostOn($account)) {
            throw ValidationException::withMessages(['company_account_id' => 'You are not assigned to this account.']);
        }

        if ($account->currency_id !== $this->baseCurrency()->id) {
            throw ValidationException::withMessages(['company_account_id' => 'DP/WD accounts must be in the base currency.']);
        }

        $brandId = $account->brand_id ?? ($data['brand_id'] ?? null);
        $brand = $brandId ? Brand::query()->find($brandId) : null;

        if (! $brand || ! $user->canPostForBrand($brand)) {
            throw ValidationException::withMessages(['brand_id' => 'Pick a brand you are assigned to.']);
        }

        $amount = $this->money($data['amount'] ?? null, 'amount');
        $fee = $account->paymentGateway ? $this->money($data['fee'] ?? 0, 'fee', allowZero: true) : '0.00';

        if (bccomp($fee, $amount, 2) >= 0 && bccomp($fee, '0', 2) === 1) {
            throw ValidationException::withMessages(['fee' => 'POTONGAN must be smaller than the amount.']);
        }

        return [
            'company_account_id' => $account->id,
            'brand_id' => $brand->id,
            'amount' => $amount,
            'fee' => $fee,
            'bonus_type_id' => null,
            'winlose' => null,
            'percentage' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBonus(JournalType $type, array $data, User $user): array
    {
        if ($user->isStaffCs()) {
            throw ValidationException::withMessages(['bonus_type_id' => 'Staff CS can only enter DP and WD.']);
        }

        $brand = Brand::query()->find($data['brand_id'] ?? null);

        if (! $brand || ! $user->canPostForBrand($brand)) {
            throw ValidationException::withMessages(['brand_id' => 'Pick a brand you are assigned to.']);
        }

        $kind = $type === JournalType::Cashback ? BonusKind::Cashback : BonusKind::Bonus;
        $bonusType = BonusType::query()
            ->where('kind', $kind)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->find($data['bonus_type_id'] ?? null);

        if (! $bonusType) {
            throw ValidationException::withMessages(['bonus_type_id' => 'NAMA BONUS is required.']);
        }

        $attributes = [
            'company_account_id' => null,
            'brand_id' => $brand->id,
            'bonus_type_id' => $bonusType->id,
            'fee' => '0.00',
        ];

        if ($type === JournalType::Cashback) {
            $winlose = $this->money($data['winlose'] ?? null, 'winlose');
            $percentage = $this->money($data['percentage'] ?? $bonusType->default_percentage, 'percentage');

            // BONUS = WINLOSE × %
            return $attributes + [
                'winlose' => $winlose,
                'percentage' => $percentage,
                'amount' => bcdiv(bcmul($winlose, $percentage, 4), '100', 2),
            ];
        }

        return $attributes + [
            'winlose' => null,
            'percentage' => null,
            'amount' => $this->money($data['amount'] ?? null, 'amount'),
        ];
    }

    /**
     * Each line is [ledgerable, amount in its own currency, IDR value].
     * Company accounts keep their currency; categories, partners and loan
     * counterparties are kept in IDR. Money journals must balance in IDR.
     */
    private function writeLines(Journal $journal): void
    {
        $amount = (string) $journal->amount;
        $fee = (string) $journal->fee;
        $net = $journal->net();
        $negate = fn (string $value): string => bcmul($value, '-1', 2);

        $lines = match ($journal->type) {
            JournalType::Deposit => [
                [$journal->companyAccount, $net, $net],
                [$this->feeCategory($journal, 'dp'), $fee, $fee],
                [$this->category('journal.gross_revenue'), $negate($amount), $negate($amount)],
            ],
            JournalType::Withdraw => [
                [$journal->companyAccount, $negate($amount), $negate($amount)],
                [$this->category('journal.gross_revenue'), $net, $net],
                [$this->feeCategory($journal, 'wd'), $fee, $fee],
            ],
            JournalType::Bonus => [[$this->category('journal.bonus'), $amount, $amount]],
            JournalType::Cashback => [[$this->category('journal.cashback'), $amount, $amount]],
            default => $this->centralMoney()->lines($journal),
        };

        $this->storeLines($journal, $lines);
    }

    /**
     * @param  array<int, array{0: mixed, 1: string, 2: string}>  $lines  [ledgerable, amount, IDR value]
     */
    private function storeLines(Journal $journal, array $lines): void
    {
        $base = $this->baseCurrency()->id;
        $sum = '0';

        foreach ($lines as [$ledgerable, $value, $idr]) {
            if ($ledgerable === null || bccomp($value, '0', 2) === 0) {
                continue;
            }

            $journal->lines()->create([
                'ledgerable_type' => $ledgerable->getMorphClass(),
                'ledgerable_id' => $ledgerable->getKey(),
                'currency_id' => $ledgerable instanceof CompanyAccount ? $ledgerable->currency_id : $base,
                'amount' => $value,
                'idr_amount' => $idr,
            ]);

            $sum = bcadd($sum, $idr, 2);
        }

        if ($journal->type->movesMoney() && bccomp($sum, '0', 2) !== 0) {
            throw new LogicException("Journal {$journal->number} does not balance ({$sum}).");
        }
    }

    private function centralMoney(): CentralMoneyEntries
    {
        return app(CentralMoneyEntries::class);
    }

    private function openPeriodFor(string $date): Period
    {
        $period = Period::forDate(Carbon::parse($date));

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['entry_date' => "The books for {$period->label()} are closed."]);
        }

        return $period;
    }

    private function nextNumber(JournalType $type, Period $period): string
    {
        $prefix = sprintf('%s-%04d%02d-', $type->prefix(), $period->year, $period->month);

        // Lock the period row so two staff saving at once can't get the same number.
        Period::query()->whereKey($period->id)->lockForUpdate()->first();

        $last = Journal::query()
            ->where('number', 'like', $prefix.'%')
            ->where('number', 'not like', '%-R')
            ->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function member(Brand $brand, string $memberId, array $attributes): Member
    {
        $member = Member::query()->firstOrCreate(
            ['brand_id' => $brand->id, 'member_id' => $memberId],
            ['first_seen_at' => now()],
        );

        // Fill bank details the first time we learn them; never overwrite.
        $member->fill(array_filter([
            'bank_id' => $member->bank_id ?? $attributes['member_bank_id'],
            'account_name' => $member->account_name ?? $attributes['member_account_name'],
        ]));

        if ($member->isDirty()) {
            $member->save();
        }

        return $member;
    }

    /**
     * Plain number with "." as decimal point. Forms strip the rupiah
     * thousand dots before calling us, so nothing is guessed here.
     */
    private function money(mixed $value, string $field, bool $allowZero = false): string
    {
        $normalized = is_string($value) ? trim($value) : $value;
        $rounded = is_numeric($normalized) ? number_format((float) $normalized, 2, '.', '') : null;

        // Compare the rounded value: 0.001 must not slip through as 0.00.
        if ($rounded === null || ($allowZero ? bccomp($rounded, '0', 2) < 0 : bccomp($rounded, '0', 2) <= 0)) {
            throw ValidationException::withMessages([$field => $allowZero ? 'Must be 0 or more.' : 'Must be more than 0.']);
        }

        return $rounded;
    }

    private function feeCategory(Journal $journal, string $direction): ?Category
    {
        $gateway = $journal->companyAccount?->paymentGateway;

        return $gateway ? $this->category($gateway->feeSourceKey($direction)) : null;
    }

    private function category(string $sourceKey): Category
    {
        return Category::query()->where('source_key', $sourceKey)->firstOrFail();
    }

    private function baseCurrency(): Currency
    {
        return once(fn () => Currency::base());
    }
}
