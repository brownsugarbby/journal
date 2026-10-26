<?php

namespace App\Filament\Pages;

use App\Enums\AccountKind;
use App\Enums\BonusKind;
use App\Enums\JournalType;
use App\Filament\Pages\Concerns\WorksWithJournals;
use App\Filament\Support\Fields;
use App\Models\Bank;
use App\Models\BonusType;
use App\Models\Brand;
use App\Models\CompanyAccount;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Member;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The staff journal, laid out like the old workbook: one tab per sheet
 * (each operational account, BONUS / CASHBACK per brand), an entry row on
 * top (Tab between cells, Enter saves), and the ledger below with running
 * SALDO. Saved rows are read-only; fixes go through Edit (inside the edit
 * window) or Void (superadmin).
 *
 * @property-read Schema $entryForm
 */
class Jurnal extends Page implements HasTable
{
    use InteractsWithTable;
    use WorksWithJournals;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Jurnal';

    protected static ?string $slug = 'jurnal';

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $entry = [];

    /**
     * @var array<string, array{label: string, kind: string, id: int}>|null
     */
    protected ?array $cachedSheets = null;

    public function mount(): void
    {
        if (! array_key_exists((string) $this->activeTab, $this->sheets())) {
            $this->activeTab = array_key_first($this->sheets());
        }

        $this->resetEntry();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->resetEntry();
    }

    // ---------------------------------------------------------------------
    // Sheets (tabs)
    // ---------------------------------------------------------------------

    /**
     * Tabs the user may see: their operational accounts, then BONUS and
     * CASHBACK for each of their brands.
     *
     * @return array<string, array{label: string, kind: string, id: int}>
     */
    public function sheets(): array
    {
        if ($this->cachedSheets !== null) {
            return $this->cachedSheets;
        }

        $user = $this->user();
        $sheets = [];

        foreach ($user->accessibleAccounts()->get() as $account) {
            $sheets["acc-{$account->id}"] = ['label' => $account->label, 'kind' => 'account', 'id' => $account->id];
        }

        // Staff CS: DP / WD sheets only, no BONUS / CASHBACK.
        foreach ($user->isStaffCs() ? [] : $user->accessibleBrands()->get() as $brand) {
            $sheets["bonus-{$brand->id}"] = ['label' => "BONUS {$brand->code}", 'kind' => 'bonus', 'id' => $brand->id];
            $sheets["cashback-{$brand->id}"] = ['label' => "CASHBACK {$brand->code}", 'kind' => 'cashback', 'id' => $brand->id];
        }

        return $this->cachedSheets = $sheets;
    }

    /**
     * @return array{label: string, kind: string, id: int}|null
     */
    public function sheet(): ?array
    {
        return $this->sheets()[$this->activeTab] ?? null;
    }

    public function account(): ?CompanyAccount
    {
        $sheet = $this->sheet();

        return $sheet && $sheet['kind'] === 'account'
            ? once(fn () => CompanyAccount::query()->with(['paymentGateway', 'bank', 'brand'])->find($sheet['id']))
            : null;
    }

    public function brand(): ?Brand
    {
        $sheet = $this->sheet();

        return $sheet && $sheet['kind'] !== 'account' ? once(fn () => Brand::query()->find($sheet['id'])) : null;
    }

    /**
     * Rebuild tabs (balance badges) and the SALDO strip after a change.
     */
    protected function refreshSheets(): void
    {
        $this->cachedSheets = null;
        $this->cacheSchema('content', null);
    }

    protected function isGateway(): bool
    {
        return $this->account()?->kind === AccountKind::Gateway;
    }

    protected function isSharedAccount(): bool
    {
        return $this->account() !== null && $this->account()->brand_id === null;
    }

    // ---------------------------------------------------------------------
    // Page layout
    // ---------------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        if ($this->sheet() === null) {
            return $schema->components([
                Section::make('No sheets assigned')
                    ->description('Ask a superadmin to assign you brands and accounts on the Users screen.'),
            ]);
        }

        return $schema
            ->components([
                Tabs::make('Sheets')
                    ->key('sheets')
                    ->livewireProperty('activeTab')
                    ->tabPanels(false)
                    ->contained(false)
                    ->tabs($this->tabComponents()),
                View::make('filament.pages.jurnal.account-summary')
                    ->visible(fn (): bool => $this->account() !== null)
                    ->viewData(fn (): array => $this->summaryData()),
                Form::make([EmbeddedSchema::make('entryForm')])
                    ->id('entry-form')
                    ->livewireSubmitHandler('save'),
                EmbeddedTable::make(),
            ]);
    }

    /**
     * @return array<string, Tab>
     */
    protected function tabComponents(): array
    {
        $balances = $this->accountBalances();

        return collect($this->sheets())
            ->map(function (array $sheet, string $key) use ($balances): Tab {
                $tab = Tab::make($sheet['label']);

                if ($sheet['kind'] === 'account' && isset($balances[$sheet['id']])) {
                    [$balance, $overMax] = $balances[$sheet['id']];
                    $tab->badge(Fields::rupiah($balance))->badgeColor($overMax ? 'danger' : 'gray');
                }

                return $tab;
            })
            ->all();
    }

    /**
     * Book balance and over-max flag per tab account, in one query.
     *
     * @return array<int, array{string, bool}>
     */
    protected function accountBalances(): array
    {
        $ids = collect($this->sheets())->where('kind', 'account')->pluck('id');
        $sums = JournalLine::query()
            ->where('ledgerable_type', (new CompanyAccount)->getMorphClass())
            ->whereIn('ledgerable_id', $ids)
            ->groupBy('ledgerable_id')
            ->pluck(DB::raw('sum(amount)'), 'ledgerable_id');

        return CompanyAccount::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(function (CompanyAccount $account) use ($sums): array {
                $balance = bcadd((string) $account->opening_balance, (string) ($sums[$account->id] ?? '0'), 2);

                return [$account->id => [$balance, $account->isOverMax($balance)]];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function summaryData(): array
    {
        $account = $this->account();
        $balance = $account->balance();

        return [
            'account' => $account,
            'balance' => $balance,
            'overMax' => $account->isOverMax($balance),
            'lastCheck' => $account->balanceChecks()->with('createdBy')->first(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cekSaldo')
                ->label('Cek Saldo')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->visible(fn (): bool => $this->account() !== null)
                ->modalDescription(fn (): string => 'Type the real balance from mBanking / the PGA dashboard. Book balance now: '.Fields::rupiah($this->account()->balance()))
                ->schema([
                    Fields::money('actual_balance')->label('Actual balance')->required()->autofocus(),
                    Textarea::make('note')->rows(2),
                ])
                ->action(function (array $data): void {
                    $check = app(JournalService::class)->checkBalance($this->account(), $data['actual_balance'], $data['note'] ?? null, $this->user());
                    $this->refreshSheets();

                    Notification::make()
                        ->title($check->isMatching() ? 'Saldo match' : 'Selisih '.Fields::rupiah($check->difference))
                        ->body('Book '.Fields::rupiah($check->book_balance).' · Actual '.Fields::rupiah($check->actual_balance))
                        ->{$check->isMatching() ? 'success' : 'danger'}()
                        ->persistent(! $check->isMatching())
                        ->send();
                }),
        ];
    }

    // ---------------------------------------------------------------------
    // Entry row
    // ---------------------------------------------------------------------

    public function entryForm(Schema $schema): Schema
    {
        $kind = $this->sheet()['kind'] ?? null;

        return $schema
            ->statePath('entry')
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4, 'xl' => $this->entryColumnCount()])
                    ->extraAttributes(['class' => 'fi-jurnal-entry-row'])
                    ->schema(match ($kind) {
                        'account' => $this->accountEntryFields(),
                        'bonus' => $this->bonusEntryFields(),
                        'cashback' => $this->cashbackEntryFields(),
                        default => [],
                    }),
            ]);
    }

    /**
     * One grid column per cell, plus one extra for each of the three wide
     * cells (TGL, name, KETERANGAN) that span two at desktop width.
     */
    protected function entryColumnCount(): int
    {
        return 3 + match ($this->sheet()['kind'] ?? null) {
            'account' => 8 + ($this->isGateway() ? 1 : 0) + ($this->isSharedAccount() ? 1 : 0),
            'cashback' => 8,
            default => 6,
        };
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

    /**
     * TGL | [BRAND] | NAMA REKENING | KELUAR | MASUK | [POTONGAN] | USERNAME | BANK | KETERANGAN | save
     *
     * @return array<int, mixed>
     */
    protected function accountEntryFields(): array
    {
        return [
            $this->dateField(),
            Select::make('brand_id')
                ->label('Brand')
                ->options(fn (): array => $this->user()->accessibleBrands()->pluck('code', 'id')->all())
                ->native()
                ->required()
                ->visible(fn (): bool => $this->isSharedAccount()),
            $this->wide(TextInput::make('member_account_name')
                ->label('Nama rekening')
                ->maxLength(255)
                ->extraInputAttributes(['data-entry-focus' => true])),
            Fields::money('keluar')->label('Keluar (WD)'),
            Fields::money('masuk')->label('Masuk (DP)'),
            Fields::money('fee')
                ->label('Potongan')
                ->visible(fn (): bool => $this->isGateway()),
            $this->memberField(),
            Select::make('member_bank_id')
                ->label('Bank')
                ->options(fn (): array => Bank::query()->where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (Bank $bank): array => [$bank->id => $bank->short_name ?: $bank->name])->all())
                ->native(),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | NAMA BONUS | KELUAR | USERNAME | KETERANGAN | save
     *
     * @return array<int, mixed>
     */
    protected function bonusEntryFields(): array
    {
        return [
            $this->dateField(),
            $this->wide($this->bonusTypeField(BonusKind::Bonus)->extraInputAttributes(['data-entry-focus' => true])),
            Fields::money('amount')->label('Keluar')->required(),
            $this->memberField(),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    /**
     * TGL | NAMA BONUS | WINLOSE | % | BONUS | USERNAME | KETERANGAN | save
     *
     * @return array<int, mixed>
     */
    protected function cashbackEntryFields(): array
    {
        $recalculate = fn (Get $get, Set $set) => $set('amount_preview', $this->cashbackPreview($get('winlose'), $get('percentage')));

        return [
            $this->dateField(),
            $this->wide($this->bonusTypeField(BonusKind::Cashback))
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) use ($recalculate): void {
                    $set('percentage', BonusType::query()->find($state)?->default_percentage);
                    $recalculate($get, $set);
                }),
            Fields::money('winlose')->label('Winlose')->required()->live(onBlur: true)->afterStateUpdated($recalculate)
                ->extraInputAttributes(['data-entry-focus' => true]),
            TextInput::make('percentage')
                ->label('%')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated($recalculate)
                // Only a superadmin / leader may change the % from the bonus type's default;
                // for staff the cell is skipped by Tab.
                ->readOnly(fn (): bool => ! $this->user()->hasFullAccess())
                ->extraInputAttributes(fn (): array => $this->user()->hasFullAccess() ? [] : ['tabindex' => '-1']),
            TextInput::make('amount_preview')
                ->label('Bonus')
                ->readOnly()
                ->dehydrated(false)
                ->extraInputAttributes(['tabindex' => '-1']),
            $this->memberField(),
            $this->wide(TextInput::make('note')->label('Keterangan')->maxLength(500)),
            $this->saveButton(),
        ];
    }

    protected function dateField(): DatePicker
    {
        return $this->wide(DatePicker::make('entry_date'))
            ->label('Tgl')
            ->native()
            ->maxDate(today())
            ->required();
    }

    protected function memberField(): TextInput
    {
        return TextInput::make('member_id')
            ->label('Username')
            ->required()
            ->maxLength(100)
            ->autocomplete(false)
            ->datalist(fn (): array => $this->memberSuggestions());
    }

    protected function bonusTypeField(BonusKind $kind): Select
    {
        return Select::make('bonus_type_id')
            ->label('Nama bonus')
            ->options(fn (): array => $this->bonusTypes($kind)->pluck('name', 'id')->all())
            ->native()
            ->required();
    }

    protected function saveButton(): Actions
    {
        return Actions::make([
            Action::make('saveEntry')
                ->label('Simpan')
                ->icon(Heroicon::OutlinedCheck)
                ->submit('save')
                ->keyBindings(['mod+s']),
        ])->verticalAlignment('end');
    }

    /**
     * Recent member IDs of the sheet's brand(s) for USERNAME autocomplete.
     *
     * @return array<int, string>
     */
    protected function memberSuggestions(): array
    {
        $brandIds = $this->account()
            ? ($this->account()->brand_id ? [$this->account()->brand_id] : $this->user()->accessibleBrands()->pluck('id')->all())
            : [$this->brand()?->id];

        return Member::query()
            ->whereIn('brand_id', $brandIds)
            ->latest('updated_at')
            ->limit(300)
            ->pluck('member_id')
            ->all();
    }

    /**
     * @return Collection<int, BonusType>
     */
    protected function bonusTypes(BonusKind $kind): Collection
    {
        return BonusType::query()
            ->where('is_active', true)
            ->where('kind', $kind)
            ->where(fn (Builder $query) => $query->whereNull('brand_id')->orWhere('brand_id', $this->brand()?->id))
            ->orderBy('name')
            ->get();
    }

    protected function cashbackPreview(mixed $winlose, mixed $percentage): ?string
    {
        $winlose = str_replace('.', '', (string) $winlose);

        if (! is_numeric($winlose) || ! is_numeric($percentage)) {
            return null;
        }

        return Fields::rupiah(bcdiv(bcmul($winlose, (string) $percentage, 4), '100', 2));
    }

    protected function resetEntry(): void
    {
        $previous = $this->entry ?? [];
        $defaults = ['entry_date' => $previous['entry_date'] ?? today()->toDateString()];
        $sheet = $this->sheet();

        if ($sheet && $sheet['kind'] === 'account' && $this->isSharedAccount()) {
            $brands = $this->user()->accessibleBrands()->pluck('id');
            $defaults['brand_id'] = $brands->contains($previous['brand_id'] ?? null) ? $previous['brand_id'] : $brands->first();
        }

        if ($sheet && $sheet['kind'] === 'cashback') {
            $type = $this->bonusTypes(BonusKind::Cashback)->first();
            $defaults['bonus_type_id'] = $previous['bonus_type_id'] ?? $type?->id;
            $defaults['percentage'] = BonusType::query()->find($defaults['bonus_type_id'])?->default_percentage;
        }

        if ($sheet && $sheet['kind'] === 'bonus') {
            $defaults['bonus_type_id'] = $previous['bonus_type_id'] ?? null;
        }

        $this->entryForm->fill($defaults);
    }

    public function save(): void
    {
        $sheet = $this->sheet();
        $data = $this->entryForm->getState();

        [$type, $payload, $amountField] = match ($sheet['kind']) {
            'account' => $this->accountPayload($data),
            'bonus' => [JournalType::Bonus, [...$data, 'brand_id' => $sheet['id']], 'amount'],
            'cashback' => [JournalType::Cashback, [...$data, 'brand_id' => $sheet['id']], 'winlose'],
        };

        try {
            $journal = app(JournalService::class)->post($type, $payload, $this->user());
        } catch (ValidationException $exception) {
            // Show service errors under the matching cell of the entry row.
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [
                    'entry.'.($key === 'amount' ? $amountField : $key) => $messages,
                ])
                ->all());
        }

        $this->afterSaved($journal);
    }

    /**
     * One row covers both DP and WD, like the sheet: fill MASUK for a deposit
     * or KELUAR for a withdrawal.
     *
     * @param  array<string, mixed>  $data
     * @return array{JournalType, array<string, mixed>, string}
     */
    protected function accountPayload(array $data): array
    {
        $masuk = filled($data['masuk'] ?? null) && (float) $data['masuk'] > 0;
        $keluar = filled($data['keluar'] ?? null) && (float) $data['keluar'] > 0;

        if ($masuk === $keluar) {
            throw ValidationException::withMessages([
                'entry.masuk' => $masuk ? 'Fill either KELUAR or MASUK, not both.' : 'Fill KELUAR (WD) or MASUK (DP).',
            ]);
        }

        return [
            $masuk ? JournalType::Deposit : JournalType::Withdraw,
            [...$data, 'company_account_id' => $this->account()->id, 'amount' => $masuk ? $data['masuk'] : $data['keluar']],
            $masuk ? 'masuk' : 'keluar',
        ];
    }

    protected function afterSaved(Journal $journal): void
    {
        $this->resetEntry();
        $this->refreshSheets();

        Notification::make()
            ->title("{$journal->type->getLabel()} ".Fields::rupiah($journal->amount).' saved')
            ->body($journal->number)
            ->success()
            ->duration(2500)
            ->send();

        $account = $journal->companyAccount;

        if ($account && $account->isOverMax()) {
            Notification::make()
                ->title("{$account->label} is over max balance")
                ->body('Saldo '.Fields::rupiah($account->balance()).' · max '.Fields::rupiah($account->max_balance).'. Transfer to pusat.')
                ->warning()
                ->persistent()
                ->send();
        }

        // Back to the first cell for the next row.
        $this->js('setTimeout(() => document.querySelector("#entry-form [data-entry-focus]")?.focus(), 50)');
    }

    // ---------------------------------------------------------------------
    // Ledger table
    // ---------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $kind = $this->sheet()['kind'] ?? null;

        return $kind === 'account'
            ? $this->accountTable($table)
            : $this->bonusTable($table, $kind === 'cashback');
    }

    /**
     * Lines on one account with running SALDO. The window sum runs over the
     * whole account in an inner query, so filters and paging on the outer
     * query never change the SALDO shown.
     */
    protected function accountTable(Table $table): Table
    {
        $account = $this->account();

        return $table
            ->query(fn (): Builder => Ledger::accountLines($account)
                ->with(['journal.member', 'journal.memberBank', 'journal.createdBy', 'journal.brand', 'journal.companyAccount', 'journal.targetAccount', 'journal.category'])
                ->when(! $this->user()->hasFullAccess(), fn (Builder $query) => $this->limitToStaffRows($query, 'j_entry_date', 'j_created_by')))
            ->columns([
                TextColumn::make('journal.entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('journal.brand.code')->label('Brand')->badge()->visible($this->isSharedAccount()),
                TextColumn::make('journal.member_account_name')->label('Nama rekening')->placeholder('-')->searchable(),
                TextColumn::make('keluar')
                    ->label('Keluar')
                    ->state(fn (JournalLine $record): ?string => $record->amount < 0 ? $this->displayAmount($record) : null)
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->color('danger')
                    ->alignEnd(),
                TextColumn::make('masuk')
                    ->label('Masuk')
                    ->state(fn (JournalLine $record): ?string => $record->amount > 0 ? $this->displayAmount($record) : null)
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->color('success')
                    ->alignEnd(),
                TextColumn::make('journal.fee')
                    ->label('Potongan')
                    ->formatStateUsing(fn ($state, JournalLine $record) => $record->journal->isReversal() || (float) $state === 0.0 ? null : Fields::rupiah($state))
                    ->alignEnd()
                    ->visible($this->isGateway()),
                TextColumn::make('running_balance')
                    ->label('Saldo')
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('journal.member.member_id')->label('Username')->searchable()->weight('bold'),
                TextColumn::make('journal.created_at')->label('Waktu')->dateTime('H:i:s'),
                TextColumn::make('journal.createdBy.username')->label('Op'),
                TextColumn::make('journal.memberBank.short_name')->label('Bank')->placeholder('-'),
                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    // Transfers out to pusat etc. have no member: describe them instead.
                    ->state(fn (JournalLine $record): ?string => $record->journal->type->isEditable() ? $record->journal->note : $record->journal->description())
                    ->placeholder('-')
                    ->wrap()
                    ->limit(60),
                $this->statusColumn(),
                TextColumn::make('journal.number')->label('No.')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions($this->journalActions(fn (JournalLine $record): Journal => $record->journal))
            ->defaultSort('journal_id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No entries yet')
            ->emptyStateDescription('Use the entry row above: fill MASUK for a DP or KELUAR for a WD, then press Enter.');
    }

    protected function bonusTable(Table $table, bool $cashback): Table
    {
        $brand = $this->brand();

        return $table
            ->query(fn (): Builder => Journal::query()
                ->where('type', $cashback ? JournalType::Cashback : JournalType::Bonus)
                ->where('brand_id', $brand?->id)
                ->with(['member', 'createdBy', 'bonusType'])
                ->when(! $this->user()->hasFullAccess(), fn (Builder $query) => $this->limitToStaffRows($query, 'entry_date', 'created_by')))
            ->columns([
                TextColumn::make('entry_date')->label('Tgl')->date('d/m/Y'),
                TextColumn::make('bonusType.name')->label('Nama bonus'),
                TextColumn::make('winlose')->label('Winlose')->formatStateUsing(fn ($state) => Fields::rupiah($state))->alignEnd()->visible($cashback),
                TextColumn::make('percentage')->label('%')->suffix('%')->visible($cashback),
                TextColumn::make('amount')
                    ->label($cashback ? 'Bonus' : 'Keluar')
                    ->formatStateUsing(fn ($state, Journal $record) => ($record->isReversal() ? '-' : '').Fields::rupiah($state))
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('member.member_id')->label('Username')->searchable()->weight('bold'),
                TextColumn::make('created_at')->label('Waktu')->dateTime('H:i:s'),
                TextColumn::make('createdBy.username')->label('Op'),
                TextColumn::make('note')->label('Keterangan')->placeholder('-')->wrap()->limit(60),
                TextColumn::make('status')
                    ->state(fn (Journal $record): ?string => $this->status($record))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'VOID' ? 'danger' : 'gray'),
                TextColumn::make('number')->label('No.')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions($this->journalActions(fn (Journal $record): Journal => $record))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No entries yet');
    }

    /**
     * KELUAR / MASUK of a DP/WD as typed (gross, POTONGAN shown apart);
     * anything else (reversal, transfer with fee...) shows what moved.
     */
    protected function displayAmount(JournalLine $line): string
    {
        $journal = $line->journal;

        return in_array($journal->type, [JournalType::Deposit, JournalType::Withdraw], true) && ! $journal->isReversal()
            ? (string) $journal->amount
            : ltrim((string) $line->amount, '-');
    }

    protected function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->state(fn (JournalLine $record): ?string => $this->status($record->journal))
            ->badge()
            ->color(fn (?string $state): string => $state === 'VOID' ? 'danger' : 'gray');
    }

    /**
     * @param  \Closure(Model): Journal  $journalOf
     * @return array<int, Action>
     */
    protected function journalActions(\Closure $journalOf): array
    {
        return [
            Action::make('edit')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->iconButton()
                ->tooltip('Edit (inside the edit window)')
                ->visible(fn (Model $record): bool => $this->user()->can('update', $journalOf($record)))
                ->modalHeading(fn (Model $record): string => 'Edit '.$journalOf($record)->number)
                ->fillForm(fn (Model $record): array => $this->editFormData($journalOf($record)))
                ->schema(fn (Model $record): array => $this->editFormFields($journalOf($record)))
                ->action(function (array $data, Model $record, Action $action) use ($journalOf): void {
                    try {
                        app(JournalService::class)->update($journalOf($record), $data, $this->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Not saved')->body(collect($exception->errors())->flatten()->join(' '))->danger()->send();
                        $action->halt();
                    }

                    $this->refreshSheets();
                    Notification::make()->title('Entry updated')->success()->send();
                }),
            $this->voidAction($journalOf),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function editFormData(Journal $journal): array
    {
        return [
            'entry_date' => $journal->entry_date->toDateString(),
            'member_id' => $journal->member?->member_id,
            'member_account_name' => $journal->member_account_name,
            'member_bank_id' => $journal->member_bank_id,
            'amount' => (int) $journal->amount,
            'fee' => (int) $journal->fee,
            'bonus_type_id' => $journal->bonus_type_id,
            'winlose' => $journal->winlose !== null ? (int) $journal->winlose : null,
            'percentage' => $journal->percentage,
            'note' => $journal->note,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function editFormFields(Journal $journal): array
    {
        $common = [$this->dateField(), $this->memberField()];

        return match ($journal->type) {
            JournalType::Deposit, JournalType::Withdraw => [
                ...$common,
                TextInput::make('member_account_name')->label('Nama rekening')->maxLength(255),
                Fields::money('amount')->label($journal->type === JournalType::Deposit ? 'Masuk' : 'Keluar')->required(),
                Fields::money('fee')->label('Potongan')->visible($journal->companyAccount?->kind === AccountKind::Gateway),
                Select::make('member_bank_id')->label('Bank')->options(Bank::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->native(),
                TextInput::make('note')->label('Keterangan')->maxLength(500),
            ],
            JournalType::Bonus => [
                ...$common,
                $this->bonusTypeField(BonusKind::Bonus),
                Fields::money('amount')->label('Keluar')->required(),
                TextInput::make('note')->label('Keterangan')->maxLength(500),
            ],
            JournalType::Cashback => [
                ...$common,
                $this->bonusTypeField(BonusKind::Cashback),
                Fields::money('winlose')->label('Winlose')->required(),
                TextInput::make('percentage')->label('%')->numeric()->required()->readOnly(! $this->user()->hasFullAccess()),
                TextInput::make('note')->label('Keterangan')->maxLength(500),
            ],
            default => [],
        };
    }
}
