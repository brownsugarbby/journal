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
use Filament\Tables\Columns\Summarizers\Summarizer;
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
                $this->typedMoneyCell('total_dp', 'dp', 'Total DP'),
                $this->typedMoneyCell('total_dp_pga', 'dp_pga', 'Total DP PGA'),
                $this->formulaColumn('dp_all', 'Total DP all', self::GREEN),
                $this->typedMoneyCell('total_wd', 'wd', 'Total WD'),
                $this->typedMoneyCell('total_wd_manual', 'wd_manual', 'Total WD manual'),
                $this->formulaColumn('wd_all', 'Total WD all', self::GREEN),
                $this->formulaColumn('in_out', 'Total in/out', self::ORANGE),
                $this->cell('winlose', 'Winlose harian', allowNegative: true, background: self::YELLOW),
                $this->cell('turnover', 'Turnover', background: self::YELLOW),
                $this->cell('from_dp', 'From DP'),
                $this->cell('form_bonus', 'Form bonus'),
                $this->formulaColumn('form_bersih_dp', 'Form bersih', self::GREEN),
                $this->cell('from_wd', 'From WD'),
                $this->cell('from_wd_manual', 'From WD manual'),
                $this->formulaColumn('form_bersih_wd', 'Form bersih', self::GREEN),
                $this->typedMoneyCell('bonus_manual', 'bonus_manual', 'Bonus manual'),
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

    // The sheet's colours, light enough for dark mode too.
    private const GREEN = 'rgb(106 168 79 / 0.16)';

    private const ORANGE = 'rgb(230 145 56 / 0.35)';

    private const YELLOW = 'rgb(255 217 102 / 0.35)';

    protected function cell(string $name, string $label, bool $integer = false, bool $allowNegative = false, ?string $background = null, ?string $totalKey = null): TextInputColumn
    {
        $totalKey ??= $name;

        return MoneyInputColumn::make($name)
            ->label($label)
            ->rules(array_filter(['nullable', 'numeric', $integer ? 'integer' : null, $allowNegative ? null : 'min:0']))
            ->disabled(fn (): bool => $this->isLocked())
            ->extraCellAttributes($background ? ['style' => "background: {$background}"] : [])
            ->summarize(Summarizer::make()->using(fn (): ?string => $this->totals()[$totalKey])->formatStateUsing(fn ($state) => Fields::rupiah($state)));
    }

    /**
     * TOTAL DP, DP PGA, WD, WD MANUAL, BONUS MANUAL typed like the sheet. Left
     * empty, the day takes the jurnal's figure (shown faintly in the cell), so
     * brands that do use the jurnal need not type them.
     */
    protected function typedMoneyCell(string $name, string $key, string $label): TextInputColumn
    {
        // TOTAL sums what the day counts: typed, else the jurnal's.
        return $this->cell($name, $label, totalKey: $key)
            ->placeholder(function (DailyPanelReport $record) use ($key): ?string {
                $jurnal = $this->rowsAreWholeBrand() ? $this->moneyByDay()->get($record->report_date->toDateString()) : null;

                return $jurnal && bccomp($jurnal[$key], '0', 2) !== 0 ? Fields::rupiah($jurnal[$key]) : null;
            });
    }

    /**
     * The sheet's formula columns: DP ALL = C+D, WD ALL = F−G, IN/OUT = E−H,
     * FORM BERSIH = L−M and O−P. Calculated, never typed.
     */
    protected function formulaColumn(string $key, string $label, string $background): TextColumn
    {
        return TextColumn::make($key)
            ->label($label)
            ->state(fn (DailyPanelReport $record): ?string => $this->figures($record)[$key])
            ->formatStateUsing(fn ($state) => Fields::rupiah($state))
            ->weight('bold')
            ->alignEnd()
            ->extraCellAttributes(['style' => "background: {$background}"])
            ->summarize(Summarizer::make()->using(fn (): ?string => $this->totals()[$key])->formatStateUsing(fn ($state) => Fields::rupiah($state)));
    }

    /**
     * Money vs panel for the day (DailyMoney::differences), red when not 0,
     * empty until the panel figure is typed.
     */
    protected function differenceColumn(string $key, string $label): TextColumn
    {
        return TextColumn::make($key)
            ->label($label)
            ->state(fn (DailyPanelReport $record): ?string => $this->figures($record)[$key])
            ->formatStateUsing(fn ($state) => Fields::rupiah($state))
            ->color(fn ($state): string => $state !== null && bccomp((string) $state, '0', 2) !== 0 ? 'danger' : 'gray')
            ->weight('bold')
            ->alignEnd()
            ->summarize(Summarizer::make()->using(fn (): ?string => $this->totals()[$key])->formatStateUsing(fn ($state) => Fields::rupiah($state)));
    }

    /**
     * Every figure of one row: typed money (or the jurnal's when empty and
     * the row is the whole brand), the formulas and the selisih.
     *
     * @return array<string, string|null>
     */
    protected function figures(DailyPanelReport $record): array
    {
        $jurnal = $this->rowsAreWholeBrand() ? $this->moneyByDay()->get($record->report_date->toDateString()) : null;
        $money = DailyMoney::withTyped($jurnal ?? DailyMoney::empty(), $record);

        return [
            ...$money,
            'form_bersih_dp' => $record->formBersihDp(),
            'form_bersih_wd' => $record->formBersihWd(),
            ...DailyMoney::differences($money, $record->from_dp, $record->form_bonus, $record->from_wd, $record->from_wd_manual),
        ];
    }

    /**
     * The TOTAL row: every column summed over the month on screen.
     *
     * @return array<string, string|null>
     */
    protected function totals(): array
    {
        return once(function (): array {
            $totals = [];

            foreach ($this->getTableQueryForExport()->get() as $record) {
                $row = [
                    ...$this->figures($record),
                    ...collect(DailyPanelReport::FIGURES)->mapWithKeys(fn (string $field): array => [$field => $record->{$field}])->all(),
                ];

                foreach ($row as $key => $value) {
                    if ($value !== null) {
                        $totals[$key] = bcadd($totals[$key] ?? '0', (string) $value, 2);
                    }
                }
            }

            return $totals + array_fill_keys([...DailyMoney::KEYS, ...DailyMoney::DIFFERENCES, ...DailyPanelReport::FIGURES, 'form_bersih_dp', 'form_bersih_wd'], null);
        });
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
