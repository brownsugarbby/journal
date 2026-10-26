<?php

namespace App\Filament\Pages;

use App\Enums\PeriodStatus;
use App\Models\Period;
use App\Models\User;
use App\Services\PeriodService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Tutup buku: close a finished month (read-only from then on, closing
 * balances saved) or reopen it. Superadmin only.
 */
class TutupBuku extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Jurnal';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Tutup Buku';

    protected static ?string $title = 'Tutup Buku';

    protected static ?string $slug = 'tutup-buku';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->canCloseBooks() ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('A month opens by itself with its first entry. Close it once it has ended and every account matches its Cek Saldo: nothing dated in a closed month can be added, edited, voided or paid. Next month carries on from the closing balances.')
                ->color('gray'),
            EmbeddedTable::make(),
        ]);
    }

    protected function periods(): PeriodService
    {
        return app(PeriodService::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Period::query()->withCount('journals')->with('closedBy'))
            ->columns([
                TextColumn::make('label')->label('Bulan')->state(fn (Period $record): string => $record->label())->weight('bold'),
                TextColumn::make('status')->badge(),
                TextColumn::make('journals_count')->label('Entries'),
                TextColumn::make('closedBy.username')->label('Closed by')->placeholder('-'),
                TextColumn::make('closed_at')->label('Closed at')->dateTime('d/m/Y H:i')->placeholder('-'),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('Tutup buku')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->visible(fn (Period $record): bool => $record->status === PeriodStatus::Open)
                    ->modalHeading(fn (Period $record): string => 'Tutup buku '.$record->label())
                    ->modalDescription(fn (Period $record): HtmlString => $this->checklist($record))
                    ->modalSubmitActionLabel('Close the month')
                    ->action(function (Period $record, Action $action): void {
                        try {
                            $this->periods()->close($record, $this->user());
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Not closed')->body(collect($exception->errors())->flatten()->join(' '))->danger()->persistent()->send();
                            $action->halt();
                        }

                        Notification::make()->title($record->label().' closed')->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('danger')
                    ->visible(fn (Period $record): bool => $record->status === PeriodStatus::Closed)
                    ->requiresConfirmation()
                    ->modalDescription('Entries dated in this month can be changed again; the closing snapshot is dropped. This is logged.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(function (array $data, Period $record): void {
                        $this->periods()->reopen($record, $data['reason'], $this->user());
                        Notification::make()->title($record->label().' reopened')->warning()->send();
                    }),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('year')->orderByDesc('month'))
            ->paginated(false)
            ->emptyStateHeading('No months yet')
            ->emptyStateDescription('A month appears here after its first entry.');
    }

    protected function checklist(Period $period): HtmlString
    {
        $checks = $this->periods()->checks($period);
        $list = fn (array $items, string $color): string => collect($items)
            ->map(fn (string $item): string => '<li style="color: var(--'.$color.'-600)">'.e($item).'</li>')
            ->join('');

        if ($checks['blockers'] === [] && $checks['warnings'] === []) {
            return new HtmlString('All checks pass. Closing makes this month read-only and saves the closing balances.');
        }

        return new HtmlString(
            ($checks['blockers'] ? '<strong>Fix these first:</strong><ul style="margin: .25rem 0 .75rem 1rem; list-style: disc">'.$list($checks['blockers'], 'danger').'</ul>' : '')
            .($checks['warnings'] ? '<strong>Please check:</strong><ul style="margin: .25rem 0 0 1rem; list-style: disc">'.$list($checks['warnings'], 'warning').'</ul>' : '')
        );
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
