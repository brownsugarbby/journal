<?php

namespace App\Filament\Pages\Reports\Concerns;

use App\Models\Brand;
use App\Models\User;
use App\Reports\ReportColumn;
use App\Reports\ReportTable;
use App\Support\ReportExporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A laporan page: filters on top (public properties, kept in the URL by
 * the page), one ReportTable shown as a table, and Excel / PDF downloads
 * of exactly what is on screen.
 */
trait IsReportPage
{
    use InteractsWithTable;

    protected ?ReportTable $cachedReport = null;

    abstract protected function buildReport(): ReportTable;

    /**
     * @return array<int, mixed>
     */
    protected function filterFields(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->hasFullAccess() ?? false;
    }

    public function report(): ReportTable
    {
        return $this->cachedReport ??= $this->buildReport();
    }

    /**
     * Any filter change rebuilds the report.
     */
    public function updated(string $property): void
    {
        $this->cachedReport = null;
        $this->cacheSchema('content', null);
        $this->resetTable();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 4])
                ->schema($this->filterFields())
                ->visible(fn (): bool => $this->filterFields() !== []),
            Text::make(fn (): string => $this->report()->subtitle)->color('gray'),
            EmbeddedTable::make(),
            ...array_map(fn (string $note) => Text::make($note)->color('gray'), $this->report()->notes),
        ]);
    }

    public function table(Table $table): Table
    {
        $columns = $this->report()->columns;

        return $table
            ->records(fn (): array => $this->report()->keyedRows())
            ->columns(array_map(fn (ReportColumn $column): TextColumn => TextColumn::make($column->key)
                ->label($column->label)
                ->formatStateUsing(fn ($state) => $column->format($state))
                ->alignEnd($column->isNumeric())
                ->weight(fn (array $record): ?string => in_array($record['_style'] ?? null, ['section', 'total'], true) ? 'bold' : null)
                ->color(fn (array $record): ?string => match (true) {
                    in_array($column->key, $record['_warn'] ?? [], true) => 'danger',
                    ($record['_style'] ?? null) === 'muted' => 'gray',
                    default => null,
                })
                ->wrap($column->key === 'keterangan'), $columns))
            ->paginated(false)
            ->emptyStateHeading(__('No data for this filter'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn (): StreamedResponse => app(ReportExporter::class)->excel($this->report())),
            Action::make('pdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(fn (): StreamedResponse => app(ReportExporter::class)->pdf($this->report())),
        ];
    }

    // ---------------------------------------------------------------------
    // Shared filters
    // ---------------------------------------------------------------------

    protected function monthField(): Select
    {
        return Select::make('month')
            ->label('Month')
            ->options(collect(range(0, 23))->mapWithKeys(fn (int $i): array => [
                today()->startOfMonth()->subMonths($i)->format('Y-m') => today()->startOfMonth()->subMonths($i)->translatedFormat('F Y'),
            ])->all())
            ->native()
            ->selectablePlaceholder(false)
            ->live();
    }

    protected function brandField(?string $allLabel = null): Select
    {
        return Select::make('brand')
            ->label('Brand')
            ->options(Brand::query()->orderBy('code')->pluck('code', 'id')->all())
            ->placeholder($allLabel ?? __('All brands'))
            ->native()
            ->live();
    }

    protected function monthStart(): Carbon
    {
        $date = filled($this->month ?? null) ? Carbon::createFromFormat('Y-m', $this->month)?->startOfMonth() : null;

        return $date ?? today()->startOfMonth();
    }

    protected function brandModel(): ?Brand
    {
        return filled($this->brand ?? null) ? Brand::query()->find($this->brand) : null;
    }

    protected function brandLabel(): string
    {
        return $this->brandModel()?->code ?? __('All brands');
    }
}
