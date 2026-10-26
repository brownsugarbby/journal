<?php

namespace App\Models;

use App\Enums\AdjustmentDirection;
use App\Enums\ExpenseDirection;
use App\Enums\JournalType;
use App\Enums\LoanKind;
use App\Enums\PartnerKind;
use App\Policies\JournalPolicy;
use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One thing staff did (a DP, a WD, a bonus...). Never deleted: a mistake is
 * voided, which posts a reversal journal with the opposite lines.
 */
#[Guarded(['id'])]
#[UsePolicy(JournalPolicy::class)]
class Journal extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'entry_date' => 'date',
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'winlose' => 'decimal:2',
            'percentage' => 'decimal:2',
            'foreign_amount' => 'decimal:2',
            'kurs' => 'decimal:6',
            'is_manual' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('journal')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<CompanyAccount, $this>
     */
    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class);
    }

    /**
     * Destination of a transfer, or the THB/USD account of a currency purchase.
     *
     * @return BelongsTo<CompanyAccount, $this>
     */
    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'target_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ExpenseStatus, $this>
     */
    public function expenseStatus(): BelongsTo
    {
        return $this->belongsTo(ExpenseStatus::class);
    }

    /**
     * @return BelongsTo<LoanCounterparty, $this>
     */
    public function loanCounterparty(): BelongsTo
    {
        return $this->belongsTo(LoanCounterparty::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The `kind` column as its enum (direction of an expense, loan kind...).
     */
    public function kindEnum(): ExpenseDirection|LoanKind|PartnerKind|AdjustmentDirection|null
    {
        return match ($this->type) {
            JournalType::Expense => ExpenseDirection::tryFrom((string) $this->kind),
            JournalType::Loan => LoanKind::tryFrom((string) $this->kind),
            JournalType::Partner => PartnerKind::tryFrom((string) $this->kind),
            JournalType::Adjustment => AdjustmentDirection::tryFrom((string) $this->kind),
            default => null,
        };
    }

    /**
     * One-line KETERANGAN for rows that have no member (central money).
     */
    public function description(): string
    {
        $text = match ($this->type) {
            JournalType::Transfer => "Transfer {$this->companyAccount?->label} → {$this->targetAccount?->label}",
            JournalType::CurrencyPurchase => sprintf(
                'Beli %s %s @ %s',
                $this->targetAccount?->currency?->code,
                number_format((float) $this->foreign_amount, 2, ',', '.'),
                rtrim(rtrim(number_format((float) $this->kurs, 6, ',', '.'), '0'), ','),
            ),
            JournalType::Expense => $this->category?->name,
            JournalType::Payroll => $this->payRun?->label(),
            JournalType::Loan, JournalType::Partner, JournalType::Adjustment => trim($this->kindEnum()?->getLabel().' '.($this->loanCounterparty?->name ?? $this->partner?->name)),
            default => $this->member?->member_id,
        };

        return trim($text.($this->note ? " · {$this->note}" : ''), ' ·');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function memberBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'member_bank_id');
    }

    /**
     * @return BelongsTo<BonusType, $this>
     */
    public function bonusType(): BelongsTo
    {
        return $this->belongsTo(BonusType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<Journal, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Journal::class, 'reversal_of_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * Amount after POTONGAN: SISA DP / SISA WD in the PGA sheet.
     */
    public function net(): string
    {
        return bcsub((string) $this->amount, (string) $this->fee, 2);
    }

    public function isWithinEditWindow(): bool
    {
        $minutes = (int) app(AppSettings::class)->get('staff_edit_window_minutes');

        return $minutes > 0 && $this->created_at->gt(now()->subMinutes($minutes));
    }
}
