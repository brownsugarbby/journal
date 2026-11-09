<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Support\Fields;
use App\Filament\Support\MoneyInputColumn;
use App\Models\Brand;
use App\Models\DailyPanelReport;
use App\Models\GamePanel;
use App\Models\Period;
use App\Models\User;
use App\Reports\DailyMoney;
use BackedEnum;
use Carbon\CarbonPeriod;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The game panel's daily figures (coin side), edited cell by cell like the
 * Report Tutup Buku sheet: one row per date of the month, per brand.
 */
class DailyPanelReportPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Daily Panel Report';

    protected static ?string $title = 'Daily Panel Report';

    protected static ?string $slug = 'daily-panel-report';

    #[Url(as: 'brand')]
    public ?string $activeTab = null;

    #[Url]
    public ?string $month = null;

    /**
     * The game panel shown when the brand has more than one: each panel gets
     * its own row per day.
     */
    #[Url]
    public ?string $panel = null;

    /**
     * @var array<string, SupportCollection<string, array<string, string>>>
     */
    protected array $moneyCache = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && ($user->hasFullAccess() || ($user->can_enter_panel_report && ! $user->isStaffCs()));
    }

    public function mount(): void
    {
        $brands = $this->brands();

        if (! $brands->contains('id', (int) $this->activeTab)) {
            $this->activeTab = (string) $brands->first()?->id;
        }

        $this->month = $this->monthDate()->format('Y-m');
        $this->pickPanel();
        $this->ensureRows();
    }

    public function updatedActiveTab(): void
    {
        $this->pickPanel();
        $this->ensureRows();
        // Columns depend on the brand's panels: rebuild the table built at boot.
        $this->resetTable();
    }

    public function updatedPanel(): void
    {
        $this->pickPanel();
        $this->ensureRows();
        $this->resetTable();
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
        $this->ensureRows();
    }

    /**
     * Active game panels of the brand on screen.
     *
     * @return Collection<int, GamePanel>
     */
    protected function panels(): Collection
    {
        return GamePanel::query()
            ->where('brand_id', (int) $this->activeTab)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    protected function pickPanel(): void
    {
        $panels = $this->panels();

        if (! $panels->contains('id', (int) $this->panel)) {
            $this->panel = $panels->isEmpty() ? null : (string) $panels->first()->id;
        }
    }

    protected function panelId(): ?int
    {
        return filled($this->panel) ? (int) $this->panel : null;
    }

    /**
     * The rows cover the whole brand (no panels, or just one), so the jurnal
     * totals and the selisih can sit next to them.
     */
    protected function rowsAreWholeBrand(): bool
    {
        return $this->panels()->count() <= 1;
    }

    /**
     * @return Collection<int, Brand>
     */
    protected function brands(): Collection
    {
        return once(fn () => $this->user()->accessibleBrands()->get());
    }

    protected function monthDate(): Carbon
    {
        $date = $this->month ? Carbon::createFromFormat('Y-m', $this->month)?->startOfMonth() : null;

        return $date && ! $date->isFuture() ? $date : today()->startOfMonth();
    }

    /**
     * One row per date, from the 1st up to today (or the month's end), so
     * staff only fill cells, never add rows.
     */
    protected function ensureRows(): void
    {
        if (blank($this->activeTab)) {
            return;
        }

        $start = $this->monthDate();
        $end = $start->isSameMonth(today()) ? today() : $start->copy()->endOfMonth();
        $rows = fn () => DailyPanelReport::query()
            ->where('brand_id', (int) $this->activeTab)
            ->whereDate('report_date', '>=', $start)
            ->whereDate('report_date', '<=', $end);

        // A brand that just got its one panel: its earlier rows (no panel)
        // become that panel's, so the figures already typed stay on screen.
        if ($this->panelId() !== null && $this->panels()->count() === 1) {
            $taken = $rows()->where('game_panel_id', $this->panelId())->pluck('report_date')->map(fn (Carbon $date): string => $date->toDateString());

            $rows()->whereNull('game_panel_id')->get()
                ->reject(fn (DailyPanelReport $row): bool => $taken->contains($row->report_date->toDateString()))
                ->each(fn (DailyPanelReport $row) => $row->update(['game_panel_id' => $this->panelId()]));
        }

        $existing = $rows()
            ->where('game_panel_id', $this->panelId())
            ->pluck('report_date')
            ->map(fn (Carbon $date): string => $date->toDateString());

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (! $existing->contains($date->toDateString())) {
                DailyPanelReport::query()->create([
                    'report_date' => $date->toDateString(),
                    'brand_id' => (int) $this->activeTab,
                    'game_panel_id' => $this->panelId(),
                ]);
            }
        }
    }

    protected function isLocked(): bool
    {
        $start = $this->monthDate();

        return ! (Period::query()->where(['year' => $start->year, 'month' => $start->month])->first()?->isOpen() ?? true);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 3])->schema([
                Select::make('month')
                    ->label('Month')
                    ->options($this->monthOptions())
                    ->native()
                    ->selectablePlaceholder(false)
                    ->live(),
                Select::make('panel')
                    ->label('Game panel')
                    ->options(fn (): array => $this->panels()->pluck('name', 'id')->all())
                    ->native()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visible(fn (): bool => $this->panels()->count() > 1)
                    ->helperText(__('One row per panel per day. Jurnal totals and selisih per brand: Laporan Tutup Buku.')),
            ]),
            Tabs::make('Brands')
                ->key('brands')
                ->livewireProperty('activeTab')
                ->tabPanels(false)
                ->contained(false)
                ->tabs($this->brands()->mapWithKeys(fn (Brand $brand): array => [(string) $brand->id => Tab::make($brand->code)])->all()),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(fn (int $i): array => [
                today()->startOfMonth()->subMonths($i)->format('Y-m') => today()->startOfMonth()->subMonths($i)->translatedFormat('F Y'),
            ])
            ->all();
    }

    public function table(Table $table): Table
    {
        $start = $this->monthDate();

        return $table
            ->query(fn (): Builder => DailyPanelReport::query()
                ->where('brand_id', (int) $this->activeTab)
                ->where('game_panel_id', $this->panelId())
                ->whereDate('report_date', '>=', $start)
                ->whereDate('report_date', '<=', $start->copy()->endOfMonth()))
            ->columns([
                TextColumn::make('report_date')->label('Tanggal')->date('d-m-Y')->weight('bold'),
                $this->cell('new_member', 'New member', integer: true),
                $this->moneyColumn('dp', 'Total DP'),
                $this->moneyColumn('dp_pga', 'Total DP PGA'),
                $this->moneyColumn('dp_all', 'Total DP all'),
                $this->moneyColumn('wd', 'Total WD'),
                $this->moneyColumn('wd_manual', 'Total WD manual'),
                $this->moneyColumn('wd_all', 'Total WD all'),
                $this->moneyColumn('in_out', 'Total in/out'),
                $this->cell('winlose', 'Winlose harian', allowNegative: true),
                $this->cell('turnover', 'Turnover'),
                $this->cell('from_dp', 'From DP'),
                $this->cell('form_bonus', 'Form bonus'),
                TextColumn::make('form_bersih_dp')
                    ->label('Form bersih')
                    ->state(fn (DailyPanelReport $record): ?string => $record->formBersihDp())
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->color('gray')
                    ->alignEnd(),
                $this->cell('from_wd', 'From WD'),
                $this->cell('from_wd_manual', 'From WD manual'),
                TextColumn::make('form_bersih_wd')
                    ->label('Form bersih')
                    ->state(fn (DailyPanelReport $record): ?string => $record->formBersihWd())
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->color('gray')
                    ->alignEnd(),
                $this->moneyColumn('bonus_manual', 'Bonus manual'),
                $this->differenceColumn('selisih_dp', 'Selisih DP'),
                $this->differenceColumn('selisih_wd', 'Selisih WD'),
                $this->differenceColumn('selisih_bonus', 'Selisih bonus'),
                TextColumn::make('updated_by')
                    ->label('Op')
                    ->formatStateUsing(fn ($state) => User::query()->find($state)?->username)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->defaultSort('report_date')
            ->paginated(false)
            ->emptyStateHeading(__('Pick a brand'));
    }

    protected function cell(string $name, string $label, bool $integer = false, bool $allowNegative = false): TextInputColumn
    {
        return MoneyInputColumn::make($name)
            ->label($label)
            ->rules(array_filter(['nullable', 'numeric', $integer ? 'integer' : null, $allowNegative ? null : 'min:0']))
            ->disabled(fn (): bool => $this->isLocked());
    }

    /**
     * A read-only money column of the sheet (TOTAL DP … BONUS MANUAL),
     * summed from the jurnal. Leaders and superadmins only: admins who enter
     * panel figures do not see the brand's money totals (as in the reports).
     */
    protected function moneyColumn(string $key, string $label): TextColumn
    {
        return TextColumn::make("money_{$key}")
            ->label($label)
            ->state(fn (DailyPanelReport $record): string => ($this->moneyByDay()->get($record->report_date->toDateString()) ?? DailyMoney::empty())[$key])
            ->formatStateUsing(fn ($state) => Fields::rupiah($state))
            ->color('gray')
            ->alignEnd()
            ->visible(fn (): bool => $this->user()->hasFullAccess() && $this->rowsAreWholeBrand());
    }

    /**
     * Jurnal vs panel for the day (DailyMoney::differences), red when not 0,
     * empty until the panel figure is typed.
     */
    protected function differenceColumn(string $key, string $label): TextColumn
    {
        return TextColumn::make($key)
            ->label($label)
            ->state(function (DailyPanelReport $record) use ($key): ?string {
                $money = $this->moneyByDay()->get($record->report_date->toDateString()) ?? DailyMoney::empty();

                return DailyMoney::differences($money, $record->from_dp, $record->form_bonus, $record->from_wd, $record->from_wd_manual)[$key];
            })
            ->formatStateUsing(fn ($state) => Fields::rupiah($state))
            ->color(fn ($state): string => $state !== null && bccomp((string) $state, '0', 2) !== 0 ? 'danger' : 'gray')
            ->weight('bold')
            ->alignEnd()
            ->visible(fn (): bool => $this->user()->hasFullAccess() && $this->rowsAreWholeBrand());
    }

    /**
     * Jurnal totals per day for the brand and month on screen.
     *
     * @return SupportCollection<string, array<string, string>>
     */
    protected function moneyByDay(): SupportCollection
    {
        $start = $this->monthDate();
        $key = $this->activeTab.'|'.$start->format('Y-m');

        return $this->moneyCache[$key] ??= DailyMoney::byDay($start->toDateString(), $start->copy()->endOfMonth()->toDateString(), (int) $this->activeTab);
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
