<?php

namespace App\Filament\Resources\PayRuns\Pages;

use App\Enums\PayColumnKind;
use App\Enums\PayType;
use App\Filament\Resources\PayRuns\PayRunResource;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\PayColumn;
use App\Models\PayItem;
use App\Models\PayRun;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\PayrollPdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Gaji / UM sheet: PT · JABATAN · LAMA KERJA · NAMA · H D's own columns ·
 * GAJI (or UM FULL) · POTONG · PINJAMAN · SISA, edited cell by cell while
 * draft, with the per-currency totals, KURS and TOTAL ALL on top.
 *
 * @property PayRun $record
 */
class PayRunSheet extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PayRunResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->label();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Pay date '.$this->record->pay_date->format('d/m/Y').' · '.$this->record->status->getLabel()
            .($this->record->isPaid() ? ' by '.$this->record->paidBy?->username.' on '.$this->record->paid_at?->format('d/m/Y H:i') : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pay-runs.totals')
                ->viewData(fn (): array => ['totals' => $this->payroll()->totals($this->record), 'run' => $this->record]),
            EmbeddedTable::make(),
        ]);
    }

    protected function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    protected function isLocked(): bool
    {
        return $this->record->isPaid();
    }

    /**
     * Rebuild the totals block after a cell changes.
     */
    protected function refreshTotals(): void
    {
        $this->record->refresh();
        $this->cacheSchema('content', null);
    }

    // ---------------------------------------------------------------------
    // Header actions
    // ---------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncEmployees')
                ->label('Add new employees')
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray')
                ->visible(fn (): bool => ! $this->isLocked())
                ->action(function (): void {
                    $added = $this->payroll()->syncEmployees($this->record);
                    $this->refreshTotals();
                    Notification::make()->title($added ? "{$added} employee(s) added" : 'Everyone is already on the sheet')->success()->send();
                }),
            $this->payAction(),
            Action::make('unpay')
                ->label('Unpay')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (): bool => $this->isLocked())
                ->requiresConfirmation()
                ->modalDescription('Voids the payment journals (balances come back) and reopens the sheet for editing.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(function (array $data): void {
                    $this->payroll()->unpay($this->record, $data['reason'], $this->user());
                    $this->refreshTotals();
                    Notification::make()->title('Payment voided, sheet reopened')->success()->send();
                }),
            Action::make('recapPdf')
                ->label('Recap PDF')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->action(fn (): StreamedResponse => app(PayrollPdf::class)->recap($this->record)),
            Action::make('payslipsPdf')
                ->label('All payslips PDF')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->action(fn (): StreamedResponse => app(PayrollPdf::class)->payslips($this->record)),
        ];
    }

    protected function payAction(): Action
    {
        $currencies = fn (): array => $this->record->items()->distinct()->pluck('currency_id')->all();

        return Action::make('pay')
            ->label('Pay')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (): bool => ! $this->isLocked())
            ->modalHeading(fn (): string => 'Pay '.$this->record->label())
            ->modalDescription('Posts one journal per currency. Baht and dollars are valued at the paying account\'s average kurs.')
            ->fillForm(fn (): array => [
                'pay_date' => $this->record->pay_date->isFuture() ? today()->toDateString() : $this->record->pay_date->toDateString(),
                'accounts' => collect($currencies())->mapWithKeys(fn (int $id): array => [
                    $id => $this->payroll()->defaultAccount(Currency::query()->find($id))?->id,
                ])->all(),
            ])
            ->schema(fn (): array => [
                DatePicker::make('pay_date')->label('Pay date')->native()->maxDate(today())->required(),
                ...collect($currencies())->map(function (int $currencyId): Select {
                    $currency = Currency::query()->find($currencyId);
                    $needed = $this->payroll()->totals($this->record)['currencies'][$currencyId]['sisa'] ?? '0';

                    return Select::make("accounts.{$currencyId}")
                        ->label("Pay {$currency->code} from")
                        ->options(CompanyAccount::query()->where('is_active', true)->where('currency_id', $currencyId)->orderBy('tier')->orderBy('label')->pluck('label', 'id'))
                        ->native()
                        ->required()
                        ->live()
                        ->helperText(function ($state) use ($currency, $needed): string {
                            $account = CompanyAccount::query()->find($state);
                            $text = "Needed {$currency->code} ".number_format((float) $needed, $currency->decimals, ',', '.');

                            if (! $account) {
                                return $text;
                            }

                            $balance = $account->balance();
                            $text .= ' · balance '.number_format((float) $balance, $currency->decimals, ',', '.');

                            if (! $currency->is_base) {
                                $kurs = $account->averageKurs();
                                $text .= ' · kurs '.($kurs ? rtrim(rtrim(number_format((float) $kurs, 4, ',', '.'), '0'), ',') : 'none yet');
                            }

                            return $text.(bccomp($balance, (string) $needed, 2) < 0 ? ' · NOT ENOUGH' : '');
                        });
                })->all(),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $this->payroll()->pay($this->record, $data['accounts'] ?? [], $this->user(), $data['pay_date']);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Not paid')->body(collect($exception->errors())->flatten()->join(' '))->danger()->persistent()->send();
                    $action->halt();
                }

                $this->refreshTotals();
                Notification::make()->title($this->record->label().' paid')->success()->send();
            });
    }

    // ---------------------------------------------------------------------
    // Sheet grid
    // ---------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $isGaji = $this->record->pay_type === PayType::Gaji;

        return $table
            ->query(fn (): Builder => PayItem::query()
                ->where('pay_run_id', $this->record->id)
                ->with(['employee.company', 'employee.bank', 'currency', 'values.payColumn']))
            ->columns([
                TextColumn::make('employee.company.short_code')->label('PT')->badge(),
                TextColumn::make('employee.position')->label('Jabatan')->placeholder('-'),
                TextColumn::make('employee.name')->label('Nama')->weight('bold')->searchable(),
                TextColumn::make('lama_kerja')
                    ->label('Lama kerja')
                    ->state(fn (PayItem $record): ?string => $record->employee->lengthOfService())
                    ->placeholder('-')
                    ->toggleable(),
                ...$this->customColumns(),
                $this->amountCell('gross', $isGaji ? 'Gaji' : 'UM full'),
                $this->amountCell('potong', 'Potong'),
                $this->amountCell('loan_repayment', 'Pinjaman')
                    ->tooltip('Loan repayment deducted from pay (needs a Loan Counterparty linked to the employee)'),
                TextColumn::make('sisa')
                    ->label($isGaji ? 'Sisa' : 'Sisa UM')
                    ->state(fn (PayItem $record): string => $record->sisa())
                    ->formatStateUsing(fn ($state, PayItem $record): string => number_format((float) $state, $record->currency->decimals, ',', '.'))
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('currency.code')->label('Cur')->badge()->color('gray'),
                TextColumn::make('employee.bank.short_name')->label('Bank')->placeholder('-')->toggleable(),
                TextColumn::make('employee.account_number')->label('No rek')->placeholder('-')->toggleable(),
                TextColumn::make('employee.account_name')->label('Nama rek')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextInputColumn::make('note')
                    ->label('Keterangan')
                    ->disabled(fn (): bool => $this->isLocked())
                    ->afterStateUpdated(fn () => $this->refreshTotals()),
            ])
            ->recordActions([
                Action::make('payslip')
                    ->label('Slip')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->action(fn (PayItem $record): StreamedResponse => app(PayrollPdf::class)->payslips($this->record, $record)),
                Action::make('remove')
                    ->icon(Heroicon::OutlinedXMark)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Remove from this sheet')
                    ->visible(fn (): bool => ! $this->isLocked())
                    ->requiresConfirmation()
                    ->action(function (PayItem $record): void {
                        $record->delete();
                        $this->refreshTotals();
                    }),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading('No employees on this sheet')
            ->emptyStateDescription('Add employees with a Gaji / UM amount on People → Employees, then use "Add new employees".');
    }

    protected function amountCell(string $name, string $label): TextInputColumn
    {
        return TextInputColumn::make($name)
            ->label($label)
            ->type('number')
            ->step('any')
            ->rules(['required', 'numeric', 'min:0'])
            ->disabled(fn (): bool => $this->isLocked())
            ->afterStateUpdated(fn () => $this->refreshTotals())
            ->extraInputAttributes(['style' => 'min-width: 7.5rem; text-align: right']);
    }

    /**
     * One editable column per active pay column of this run's type.
     *
     * @return array<int, TextInputColumn>
     */
    protected function customColumns(): array
    {
        return PayColumn::query()
            ->where('pay_type', $this->record->pay_type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PayColumn $column): TextInputColumn => TextInputColumn::make("col_{$column->id}")
                ->label($column->name)
                ->tooltip($column->kind->getLabel())
                ->type($column->kind === PayColumnKind::Info ? 'text' : 'number')
                ->rules($column->kind === PayColumnKind::Info ? ['nullable', 'max:255'] : ['nullable', 'numeric', 'min:0'])
                ->state(function (PayItem $record) use ($column): mixed {
                    $value = $record->valueFor($column);

                    return $value?->text ?? ($value?->value !== null ? (float) $value->value : null);
                })
                ->updateStateUsing(function (PayItem $record, $state) use ($column): mixed {
                    $this->payroll()->setColumnValue($record, $column->id, $state);
                    $this->refreshTotals();

                    return $state;
                })
                ->disabled(fn (): bool => $this->isLocked())
                ->extraInputAttributes(['style' => 'min-width: 6.5rem']))
            ->all();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->hasFullAccess() ?? false;
    }
}
