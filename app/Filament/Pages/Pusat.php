<?php

namespace App\Filament\Pages;

use App\Enums\AccountTier;
use App\Enums\AdjustmentDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\ExpenseDirection;
use App\Enums\JournalType;
use App\Enums\LoanKind;
use App\Enums\PartnerKind;
use App\Filament\Pages\Concerns\WorksWithJournals;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\CompanyAccount;
use App\Models\ExpenseStatus;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LoanCounterparty;
use App\Models\Partner;
use App\Models\Period;
use App\Models\User;
use App\Services\JournalService;
use App\Support\Ledger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Central money, laid out like the pusat workbook: a tab per pusat / cash
 * account (FLIPTECH, SEABANK, DOLLAR, CASH THB...), PINJAMAN, one per
 * partner (Ko TM, Ko HD) and PENGELUARAN KANTOR. Each tab has its own
 * entry row (Tab between cells, Enter saves) above its running ledger.
 *
 * Superadmins see every tab; staff allowed to enter expenses see only
 * PENGELUARAN KANTOR, paying from their own accounts.
 *
 * @property-read Schema $entryForm
 */
class Pusat extends Page implements HasTable
{
    use InteractsWithTable;
    use WorksWithJournals;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Kas Pusat';

    protected static ?string $title = 'Kas Pusat';

    protected static ?string $slug = 'pusat';

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    /**
     * PENGELUARAN KANTOR month (Y-m).
     */
    #[Url]
    public ?string $month = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $entry = [];

    /**
     * @var array<string, array{label: string, kind: string, id: int|null}>|null
     */
    protected ?array $cachedSheets = null;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && ($user->hasFullAccess() || ($user->can_enter_expense && ! $user->isStaffCs()));
    }

    public function mount(): void
    {
        if (! array_key_exists((string) $this->activeTab, $this->sheets())) {
            $this->activeTab = array_key_first($this->sheets());
        }

        $this->month = $this->monthDate()->format('Y-m');
        $this->resetEntry();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->resetEntry();
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    protected function refreshSheets(): void
    {
        $this->cachedSheets = null;
        $this->cacheSchema('content', null);
    }

    // ---------------------------------------------------------------------
    // Sheets (tabs)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{label: string, kind: string, id: int|null}>
     */
    public function sheets(): array
    {
        if ($this->cachedSheets !== null) {
            return $this->cachedSheets;
        }

        $sheets = [];

        if ($this->user()->hasFullAccess()) {
            foreach (CompanyAccount::query()->where('is_active', true)->where('tier', AccountTier::Pusat)->orderBy('label')->get() as $account) {
                $sheets["acc-{$account->id}"] = ['label' => $account->label, 'kind' => 'account', 'id' => $account->id];
            }

            $sheets['loans'] = ['label' => 'PINJAMAN', 'kind' => 'loans', 'id' => null];

            foreach (Partner::query()->where('is_active', true)->orderBy('name')->get() as $partner) {
                $sheets["partner-{$partner->id}"] = ['label' => $partner->name, 'kind' => 'partner', 'id' => $partner->id];
            }
        }

        $sheets['expenses'] = ['label' => 'PENGELUARAN KANTOR', 'kind' => 'expenses', 'id' => null];

        return $this->cachedSheets = $sheets;
    }

    /**
     * @return array{label: string, kind: string, id: int|null}
     */
    protected function sheet(): array
    {
        return $this->sheets()[$this->activeTab] ?? $this->sheets()['expenses'];
    }

    protected function kind(): string
    {
        return $this->sheet()['kind'];
    }

    public function account(): ?CompanyAccount
    {
        return $this->kind() === 'account'
            ? once(fn () => CompanyAccount::query()->with('currency')->find($this->sheet()['id']))
            : null;
    }

    protected function partner(): ?Partner
    {
        return $this->kind() === 'partner' ? once(fn () => Partner::query()->find($this->sheet()['id'])) : null;
    }

    protected function isForeign(): bool
    {
        return $this->account() !== null && ! $this->account()->isBaseCurrency();
    }

    protected function monthDate(): Carbon
    {
        $date = $this->month ? Carbon::createFromFormat('Y-m', $this->month)?->startOfMonth() : null;

        return $date && ! $date->isFuture() ? $date : today()->startOfMonth();
    }

    // ---------------------------------------------------------------------
    // Page layout
    // ---------------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Sheets')
                ->key('sheets')
                ->livewireProperty('activeTab')
                ->tabPanels(false)
                ->contained(false)
                ->tabs(collect($this->sheets())->map(fn (array $sheet): Tab => Tab::make($sheet['label']))->all()),
            View::make('filament.pages.jurnal.account-summary')
                ->visible(fn (): bool => $this->account() !== null)
                ->viewData(fn (): array => $this->accountSummary()),
            Text::make(fn (): string => $this->sheetSummary())
                ->weight('bold')
                ->visible(fn (): bool => $this->account() === null),
            Grid::make(['default' => 1, 'md' => 4])
                ->visible(fn (): bool => $this->kind() === 'expenses')
                ->schema([
                    Select::make('month')
                        ->label('Month')
                        ->options(collect(range(0, 11))->mapWithKeys(fn (int $i): array => [
                            today()->startOfMonth()->subMonths($i)->format('Y-m') => today()->startOfMonth()->subMonths($i)->translatedFormat('F Y'),
                        ])->all())
                        ->native()
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
            Form::make([EmbeddedSchema::make('entryForm')])
                ->id('entry-form')
                ->livewireSubmitHandler('save'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountSummary(): array
    {
        $account = $this->account();
        $balance = $account->balance();

        return [
            'account' => $account,
            'balance' => $balance,
            'overMax' => $account->isOverMax($balance),
            'lastCheck' => $account->balanceChecks()->with('createdBy')->first(),
            'kurs' => $account->isBaseCurrency() ? null : $account->averageKurs(),
            'idrValue' => $account->isBaseCurrency() ? null : $account->idrValue($balance),
        ];
    }

    protected function sheetSummary(): string
    {
        return match ($this->kind()) {
            'loans' => 'Outstanding: they owe us '.Fields::rupiah($this->loanTotals()['receivable'])
                .'   ·   we owe '.Fields::rupiah($this->loanTotals()['payable']),
            'partner' => 'SALDO '.Fields::rupiah($this->partner()->balance()),
            'expenses' => 'Total '.$this->monthDate()->translatedFormat('F Y').': '.Fields::rupiah($this->expenseTotal()),
            default => '',
        };
    }

    /**
     * @return array{receivable: string, payable: string}
     */
    protected function loanTotals(): array
    {
        return once(function (): array {
            $totals = ['receivable' => '0', 'payable' => '0'];

            foreach (LoanCounterparty::query()->get() as $counterparty) {
                $balance = $counterparty->balance();
                $key = bccomp($balance, '0', 2) >= 0 ? 'receivable' : 'payable';
                $totals[$key] = bcadd($totals[$key], ltrim($balance, '-'), 2);
            }

            return $totals;
        });
    }

    protected function expenseTotal(): string
    {
        $period = $this->expensePeriod();

        return $period ? (string) (Ledger::expenses($period->id)->sum('idr_value') ?: '0') : '0';
    }

    protected function expensePeriod(): ?Period
    {
        $date = $this->monthDate();

        return Period::query()->where(['year' => $date->year, 'month' => $date->month])->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cekSaldo')
                ->label('Cek Saldo')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->visible(fn (): bool => $this->account() !== null)
                ->modalDescription(fn (): string => 'Type the real balance. Book balance now: '.Fields::rupiah($this->account()->balance()))
                ->schema([
                    Fields::money('actual_balance')->label('Actual balance')->required()->autofocus(),
                    Textarea::make('note')->rows(2),
                ])
                ->action(function (array $data): void {
                    $check = app(JournalService::class)->checkBalance($this->account(), $data['actual_balance'], $data['note'] ?? null, $this->user());
                    $this->refreshSheets();

                    Notification::make()
                        ->title($check->isMatching() ? 'Saldo match' : 'Selisih '.Fields::rupiah($check->difference))
                        ->{$check->isMatching() ? 'success' : 'danger'}()
                        ->send();
                }),
            Action::make('adjustment')
                ->label('Penyesuaian')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible(fn (): bool => $this->account() !== null && $this->user()->hasFullAccess())
                ->modalDescription('Corrects the book balance (e.g. after Cek Saldo). Goes to "Penyesuaian Saldo", outside the P&L.')
                ->schema([
                    Select::make('kind')->label('Jenis')->options(AdjustmentDirection::class)->required()->native(),
                    Fields::money('amount')->label('Jumlah')->required(),
                    Textarea::make('note')->label('Reason')->required()->rows(2),
                ])
                ->action(function (array $data): void {
                    $this->post(JournalType::Adjustment, [...$data, 'company_account_id' => $this->account()->id]);
                }),
        ];
    }

    // ---------------------------------------------------------------------
    // Entry rows
    // ---------------------------------------------------------------------

    public function entryForm(Schema $schema): Schema
    {
        $fields = match ($this->kind()) {
            'account' => $this->isForeign() ? $this->purchaseFields() : $this->transferFields(),
            'loans' => $this->loanFields(),
            'partner' => $this->partnerFields(),
            default => $this->expenseFields(),
        };

        return $schema
            ->statePath('entry')
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4, 'xl' => count($fields) + 3])->schema($fields),
            ]);
    }

    /**
     * TGL | DARI | KE | JUMLAH | BIAYA ADM | KETERANGAN
     *
     * @return array<int, mixed>
     */
    protected function transferFields(): array
    {
        $sameCurrency = fn (): array => $this->accountOptions(fn (Builder $query) => $query->where('currency_id', $this->account()->currency_id));

        return [
            $this->dateField(),
            $this->wide(Select::make('company_account_id')->label('Dari')->options($sameCurrency)->native()->required()
                ->extraInputAttributes(['data-entry-focus' => true])),
            Select::make('target_account_id')->label('Ke')->options($sameCurrency)->native()->required(),
            Fields::money('amount')->label('Jumlah')->required(),
            Fields::money('fee')->label('Biaya ADM'),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | DARI (rupiah) | JUMLAH valas | KURS | TOTAL RP | KETERANGAN
     *
     * @return array<int, mixed>
     */
    protected function purchaseFields(): array
    {
        $recalculate = function (Get $get, Set $set): void {
            $units = $get('foreign_amount');
            $kurs = $get('kurs');

            if (is_numeric($units) && is_numeric($kurs)) {
                $set('amount', (int) round((float) $units * (float) $kurs));
            }
        };

        return [
            $this->dateField(),
            $this->wide(Select::make('company_account_id')->label('Dari (Rp)')
                ->options(fn (): array => $this->accountOptions(fn (Builder $query) => $query->whereHas('currency', fn (Builder $query) => $query->where('is_base', true))))
                ->native()
                ->required()),
            TextInput::make('foreign_amount')
                ->label(fn (): string => 'Jumlah '.$this->account()->currency->code)
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated($recalculate)
                ->extraInputAttributes(['data-entry-focus' => true]),
            TextInput::make('kurs')
                ->label('Kurs (Rp)')
                ->numeric()
                ->minValue(0.000001)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated($recalculate)
                ->helperText('Money changer rate today'),
            Fields::money('amount')->label('Total Rp')->required()->helperText('Edit if the changer rounded'),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | NAMA | KATEGORI | KELUAR | MASUK | BANK | STATUS | WEB
     *
     * @return array<int, mixed>
     */
    protected function expenseFields(): array
    {
        return [
            $this->dateField(),
            $this->wide(TextInput::make('note')->label('Nama')->required()->maxLength(500)
                ->extraInputAttributes(['data-entry-focus' => true])),
            $this->wide(Select::make('category_id')->label('Kategori')
                ->options(fn (): array => Category::query()
                    ->where('is_active', true)
                    ->where('source', CategorySource::Manual)
                    ->where('type', CategoryType::Expense)
                    ->orderBy('sort_order')
                    ->pluck('name', 'id')
                    ->all())
                ->native()
                ->required()),
            Fields::money('keluar')->label('Keluar'),
            Fields::money('masuk')->label('Masuk'),
            Select::make('company_account_id')->label('Bank')
                ->options(fn (): array => $this->user()->hasFullAccess()
                    ? $this->accountOptions()
                    : $this->user()->accessibleAccounts()->pluck('label', 'id')->all())
                ->native()
                ->required(),
            Select::make('expense_status_id')->label('Status')
                ->options(fn (): array => ExpenseStatus::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                ->native()
                ->required(),
            Select::make('brand_id')->label('Web')
                ->options(fn (): array => $this->user()->accessibleBrands()->pluck('code', 'id')->all())
                ->native()
                ->placeholder('Shared'),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | NAMA | JENIS | JUMLAH | AKUN | KETERANGAN
     *
     * @return array<int, mixed>
     */
    protected function loanFields(): array
    {
        return [
            $this->dateField(),
            $this->wide(Select::make('loan_counterparty_id')->label('Nama')
                ->options(fn (): array => LoanCounterparty::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->native()
                ->required()
                ->extraInputAttributes(['data-entry-focus' => true])),
            Select::make('kind')->label('Jenis')->options(LoanKind::class)->native()->required(),
            Fields::money('amount')->label('Jumlah')->required(),
            Select::make('company_account_id')->label('Akun')->options(fn (): array => $this->accountOptions())->native()->required(),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | JENIS | JUMLAH | AKUN | KETERANGAN
     *
     * @return array<int, mixed>
     */
    protected function partnerFields(): array
    {
        return [
            $this->dateField(),
            Select::make('kind')->label('Jenis')->options(PartnerKind::class)->native()->required()
                ->extraInputAttributes(['data-entry-focus' => true]),
            Fields::money('amount')->label('Jumlah')->required(),
            $this->wide(Select::make('company_account_id')->label('Akun')->options(fn (): array => $this->accountOptions())->native()->required()),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * @param  (\Closure(Builder): Builder)|null  $scope
     * @return array<int, string>
     */
    protected function accountOptions(?\Closure $scope = null): array
    {
        return CompanyAccount::query()
            ->where('is_active', true)
            ->when($scope, $scope)
            ->orderBy('tier')
            ->orderBy('label')
            ->pluck('label', 'id')
            ->all();
    }

    protected function dateField(): DatePicker
    {
        return $this->wide(DatePicker::make('entry_date')->label('Tgl')->native()->maxDate(today())->required());
    }

    /**
     * @template T of \Filament\Forms\Components\Field
     *
     * @param  T  $field
     * @return T
     */
    protected function wide($field)
    {
        return $field->columnSpan(['default' => 1, 'xl' => 2]);
    }

    protected function saveButton(): Actions
    {
        return Actions::make([
            Action::make('saveEntry')->label('Simpan')->icon(Heroicon::OutlinedCheck)->submit('save'),
        ])->verticalAlignment('end');
    }

    protected function resetEntry(): void
    {
        $previous = $this->entry ?? [];
        $defaults = ['entry_date' => $previous['entry_date'] ?? today()->toDateString()];

        if ($this->kind() === 'account' && ! $this->isForeign()) {
            $defaults['target_account_id'] = $this->account()->id;
        }

        if ($this->kind() === 'expenses') {
            $defaults['expense_status_id'] = $previous['expense_status_id'] ?? ExpenseStatus::query()->where('is_active', true)->orderBy('sort_order')->value('id');
            $defaults['company_account_id'] = $previous['company_account_id'] ?? null;
        }

        if ($this->kind() === 'account' && $this->isForeign()) {
            $defaults['company_account_id'] = $previous['company_account_id'] ?? null;
        }

        $this->entryForm->fill($defaults);
    }

    public function save(): void
    {
        $data = $this->entryForm->getState();

        [$type, $payload] = match ($this->kind()) {
            'account' => $this->isForeign()
                ? [JournalType::CurrencyPurchase, [...$data, 'target_account_id' => $this->account()->id]]
                : [JournalType::Transfer, $data],
            'loans' => [JournalType::Loan, $data],
            'partner' => [JournalType::Partner, [...$data, 'partner_id' => $this->partner()->id]],
            default => [JournalType::Expense, $this->expensePayload($data)],
        };

        $this->post($type, $payload, mapAmountTo: $type === JournalType::Expense ? ($payload['kind'] === ExpenseDirection::Masuk->value ? 'masuk' : 'keluar') : 'amount');
    }

    /**
     * One row covers spending and refunds, like the PG sheet.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function expensePayload(array $data): array
    {
        $masuk = filled($data['masuk'] ?? null) && (float) $data['masuk'] > 0;
        $keluar = filled($data['keluar'] ?? null) && (float) $data['keluar'] > 0;

        if ($masuk === $keluar) {
            throw ValidationException::withMessages([
                'entry.keluar' => $masuk ? 'Fill either KELUAR or MASUK, not both.' : 'Fill KELUAR (spent) or MASUK (refund).',
            ]);
        }

        return [
            ...$data,
            'kind' => ($masuk ? ExpenseDirection::Masuk : ExpenseDirection::Keluar)->value,
            'amount' => $masuk ? $data['masuk'] : $data['keluar'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(JournalType $type, array $payload, string $mapAmountTo = 'amount'): void
    {
        try {
            $journal = app(JournalService::class)->post($type, $payload, $this->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [
                    'entry.'.($key === 'amount' ? $mapAmountTo : $key) => $messages,
                ])
                ->all());
        }

        $this->resetEntry();
        $this->refreshSheets();

        Notification::make()
            ->title($type->getLabel().' '.Fields::rupiah($journal->amount).' saved')
            ->body($journal->number)
            ->success()
            ->duration(2500)
            ->send();

        $this->js('setTimeout(() => document.querySelector("#entry-form [data-entry-focus]")?.focus(), 50)');
    }

    // ---------------------------------------------------------------------
    // Ledger tables
    // ---------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return match ($this->kind()) {
            'account' => $this->accountTable($table),
            'loans' => $this->loanTable($table),
            'partner' => $this->partnerTable($table),
            default => $this->expenseTable($table),
        };
    }

    protected function accountTable(Table $table): Table
    {
        $account = $this->account();

        return $table
            ->query(fn (): Builder => Ledger::accountLines($account)
                ->with(['journal.companyAccount', 'journal.targetAccount.currency', 'journal.category', 'journal.loanCounterparty', 'journal.partner', 'journal.member', 'journal.createdBy']))
            ->columns([
                TextColumn::make('journal.entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('journal.type')->label('Jenis')->badge(),
                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->state(fn (JournalLine $record): string => $record->journal->description())
                    ->wrap(),
                $this->moneyColumn('keluar', 'Keluar', fn (JournalLine $record): ?string => $record->amount < 0 ? ltrim((string) $record->amount, '-') : null)->color('danger'),
                $this->moneyColumn('masuk', 'Masuk', fn (JournalLine $record): ?string => $record->amount > 0 ? (string) $record->amount : null)->color('success'),
                $this->moneyColumn('running_balance', 'Saldo', fn (JournalLine $record): string => (string) $record->running_balance, decimals: $this->isForeign() ? 2 : 0)->weight('bold'),
                $this->moneyColumn('idr_amount', 'Nilai Rp', fn (JournalLine $record): string => (string) $record->idr_amount)
                    ->color('gray')
                    ->visible($this->isForeign()),
                TextColumn::make('journal.created_at')->label('Waktu')->dateTime('d/m H:i'),
                TextColumn::make('journal.createdBy.username')->label('Op'),
                $this->statusBadge(fn (JournalLine $record): Journal => $record->journal),
            ])
            ->recordActions([$this->voidAction(fn (JournalLine $record): Journal => $record->journal)])
            ->defaultSort('journal_id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No entries yet')
            ->emptyStateDescription($this->isForeign()
                ? 'Use the row above to record a purchase at today\'s money changer kurs.'
                : 'Use the row above to record a transfer, e.g. from an operational account into this one.');
    }

    protected function expenseTable(Table $table): Table
    {
        $period = $this->expensePeriod();

        return $table
            ->query(fn (): Builder => Ledger::expenses($period?->id ?? 0)
                ->with(['companyAccount', 'category', 'brand', 'createdBy', 'expenseStatus'])
                ->when(! $this->user()->hasFullAccess(), fn (Builder $query) => $this->limitToStaffRows($query, 'entry_date', 'created_by')))
            ->columns([
                TextColumn::make('entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('note')->label('Nama')->searchable()->wrap(),
                TextColumn::make('category.name')->label('Kategori'),
                $this->moneyColumn('keluar', 'Keluar', fn (Journal $record): ?string => $record->idr_value > 0 ? (string) $record->idr_value : null)->color('danger'),
                $this->moneyColumn('masuk', 'Masuk', fn (Journal $record): ?string => $record->idr_value < 0 ? ltrim((string) $record->idr_value, '-') : null)->color('success'),
                $this->moneyColumn('running_total', 'Saldo', fn (Journal $record): string => (string) $record->running_total)->weight('bold'),
                TextColumn::make('companyAccount.label')->label('Bank'),
                SelectColumn::make('expense_status_id')
                    ->label('Status')
                    ->options(fn (): array => ExpenseStatus::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->selectablePlaceholder(false)
                    // Status is not money: it can change any time the month is open.
                    ->disabled(fn (Journal $record): bool => $record->isVoided() || $record->isReversal() || ! $record->period->isOpen()
                        || (! $this->user()->hasFullAccess() && $record->created_by !== $this->user()->id)),
                TextColumn::make('brand.code')->label('Web')->badge()->placeholder('Shared'),
                TextColumn::make('created_at')->label('Waktu')->dateTime('d/m H:i'),
                TextColumn::make('createdBy.username')->label('Op'),
                $this->statusBadge(fn (Journal $record): Journal => $record),
            ])
            ->recordActions([$this->voidAction(fn (Journal $record): Journal => $record)])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No expenses this month');
    }

    protected function loanTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Ledger::loans()->with(['companyAccount', 'loanCounterparty', 'createdBy']))
            ->columns([
                TextColumn::make('entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('loanCounterparty.name')->label('Nama')->searchable()->weight('bold'),
                TextColumn::make('kind')->label('Jenis')->formatStateUsing(fn (Journal $record): ?string => $record->kindEnum()?->getLabel())->badge()
                    ->color(fn (Journal $record): string => $record->kindEnum()?->getColor() ?? 'gray'),
                $this->moneyColumn('keluar', 'Keluar', fn (Journal $record): ?string => $this->accountSign($record) < 0 ? (string) $record->amount : null)->color('danger'),
                $this->moneyColumn('masuk', 'Masuk', fn (Journal $record): ?string => $this->accountSign($record) > 0 ? (string) $record->amount : null)->color('success'),
                TextColumn::make('companyAccount.label')->label('Akun'),
                $this->moneyColumn('running_total', 'Saldo', fn (Journal $record): string => (string) $record->running_total)->weight('bold')
                    ->tooltip('> 0: owed to us · < 0: we owe'),
                TextColumn::make('note')->label('Keterangan')->placeholder('-')->wrap(),
                TextColumn::make('createdBy.username')->label('Op'),
                $this->statusBadge(fn (Journal $record): Journal => $record),
            ])
            ->recordActions([$this->voidAction(fn (Journal $record): Journal => $record)])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No loans yet')
            ->emptyStateDescription('Add people on People → Loan Counterparties, then record loans here.');
    }

    protected function partnerTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Ledger::partner($this->partner())->with(['companyAccount', 'createdBy']))
            ->columns([
                TextColumn::make('entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('kind')->label('Jenis')->formatStateUsing(fn (Journal $record): ?string => $record->kindEnum()?->getLabel())->badge()
                    ->color(fn (Journal $record): string => $record->kindEnum()?->getColor() ?? 'gray'),
                $this->moneyColumn('keluar', 'Keluar', fn (Journal $record): ?string => $this->accountSign($record) < 0 ? (string) $record->amount : null)->color('danger'),
                $this->moneyColumn('masuk', 'Masuk', fn (Journal $record): ?string => $this->accountSign($record) > 0 ? (string) $record->amount : null)->color('success'),
                TextColumn::make('companyAccount.label')->label('Akun'),
                $this->moneyColumn('running_total', 'Saldo', fn (Journal $record): string => (string) $record->running_total)->weight('bold'),
                TextColumn::make('note')->label('Keterangan')->placeholder('-')->wrap(),
                TextColumn::make('createdBy.username')->label('Op'),
                $this->statusBadge(fn (Journal $record): Journal => $record),
            ])
            ->recordActions([$this->voidAction(fn (Journal $record): Journal => $record)])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No entries yet');
    }

    /**
     * +1 money came into the company account, −1 went out (reversals flip).
     */
    protected function accountSign(Journal $journal): int
    {
        return ($journal->kindEnum()?->sign() ?? 1) * ($journal->isReversal() ? -1 : 1);
    }

    protected function moneyColumn(string $name, string $label, \Closure $state, int $decimals = 0): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->state($state)
            ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, $decimals, ',', '.'))
            ->alignEnd();
    }

    protected function statusBadge(\Closure $journalOf): TextColumn
    {
        // No header: PENGELUARAN KANTOR already has a STATUS column.
        return TextColumn::make('void_status')
            ->label('')
            ->state(fn ($record): ?string => $this->status($journalOf($record)))
            ->badge()
            ->color(fn (?string $state): string => $state === 'VOID' ? 'danger' : 'gray');
    }
}
