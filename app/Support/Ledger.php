<?php

namespace App\Support;

use App\Enums\JournalType;
use App\Models\Category;
use App\Models\CompanyAccount;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LoanCounterparty;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ledger queries with a running total (the sheets' SALDO column).
 *
 * The window sum runs in an inner query over the whole ledger, so filters,
 * search and paging on the outer query never change the totals shown.
 */
class Ledger
{
    /**
     * Lines on one company account, with `running_balance` (opening included).
     *
     * @return Builder<JournalLine>
     */
    public static function accountLines(CompanyAccount $account): Builder
    {
        $inner = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.ledgerable_type', $account->getMorphClass())
            ->where('journal_lines.ledgerable_id', $account->id)
            ->select('journal_lines.*', 'journals.entry_date as j_entry_date', 'journals.created_by as j_created_by')
            ->selectRaw(
                'cast(? as decimal(20,2)) + sum(journal_lines.amount) over (order by journal_lines.journal_id, journal_lines.id) as running_balance',
                [(string) $account->opening_balance],
            );

        return JournalLine::query()->fromSub($inner, 'journal_lines');
    }

    /**
     * Office expenses of one month, with `idr_value` (+ spent, − refund) and
     * `running_total` (the PG [MONTH] SALDO: total spent so far).
     *
     * @return Builder<Journal>
     */
    public static function expenses(int $periodId): Builder
    {
        $inner = Journal::query()
            ->join('journal_lines as pl', fn ($join) => $join
                ->on('pl.journal_id', '=', 'journals.id')
                ->where('pl.ledgerable_type', (new Category)->getMorphClass()))
            ->where('journals.type', JournalType::Expense)
            ->where('journals.period_id', $periodId)
            ->select('journals.*', 'pl.idr_amount as idr_value')
            ->selectRaw('sum(pl.idr_amount) over (order by journals.id) as running_total');

        return Journal::query()->fromSub($inner, 'journals');
    }

    /**
     * Loan journals with `running_total` of what is outstanding across all
     * counterparties, opening balances included (> 0 owed to us). Interest
     * goes to Bunga, not here.
     *
     * @return Builder<Journal>
     */
    public static function loans(): Builder
    {
        $inner = Journal::query()
            ->leftJoin('journal_lines as cp', fn ($join) => $join
                ->on('cp.journal_id', '=', 'journals.id')
                ->where('cp.ledgerable_type', (new LoanCounterparty)->getMorphClass()))
            ->where('journals.type', JournalType::Loan)
            ->select('journals.*')
            ->selectRaw(
                'cast(? as decimal(20,2)) + sum(coalesce(cp.idr_amount, 0)) over (order by journals.id) as running_total',
                [(string) LoanCounterparty::query()->sum('opening_balance')],
            );

        return Journal::query()->fromSub($inner, 'journals');
    }

    /**
     * One partner's journals with `running_total` = their money in the
     * business (opening + capital − prive).
     *
     * @return Builder<Journal>
     */
    public static function partner(Partner $partner): Builder
    {
        $inner = Journal::query()
            ->join('journal_lines as pl', fn ($join) => $join
                ->on('pl.journal_id', '=', 'journals.id')
                ->where('pl.ledgerable_type', $partner->getMorphClass())
                ->where('pl.ledgerable_id', $partner->id))
            ->select('journals.*')
            ->selectRaw(
                'cast(? as decimal(20,2)) - sum(pl.idr_amount) over (order by journals.id) as running_total',
                [(string) $partner->opening_balance],
            );

        return Journal::query()->fromSub($inner, 'journals');
    }
}
