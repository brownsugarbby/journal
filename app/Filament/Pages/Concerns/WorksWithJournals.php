<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Journal;
use App\Models\User;
use App\Services\JournalService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared by the sheet pages (Jurnal, Pusat): staff row limits, VOID badges
 * and the Void action.
 */
trait WorksWithJournals
{
    /**
     * Rebuild tabs and summaries after a change.
     */
    abstract protected function refreshSheets(): void;

    /**
     * Staff see today's rows and their own rows; superadmins see everything.
     */
    protected function limitToStaffRows(Builder $query, string $dateColumn, string $creatorColumn): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereDate($dateColumn, today())
            ->orWhere($creatorColumn, $this->user()->id));
    }

    protected function status(Journal $journal): ?string
    {
        return match (true) {
            $journal->isVoided() => 'VOID',
            $journal->isReversal() => 'Reversal',
            default => null,
        };
    }

    /**
     * @param  Closure(Model): Journal  $journalOf  the journal behind a table row
     */
    protected function voidAction(Closure $journalOf): Action
    {
        return Action::make('void')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->iconButton()
            ->color('danger')
            ->tooltip('Void (posts a reversal)')
            ->visible(fn (Model $record): bool => $this->user()->can('void', $journalOf($record)))
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record): string => 'Void '.$journalOf($record)->number.'?')
            ->modalDescription('The entry stays in the ledger marked VOID, and a reversal row brings the balance back.')
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(function (array $data, Model $record) use ($journalOf): void {
                app(JournalService::class)->void($journalOf($record), $data['reason'], $this->user());
                $this->refreshSheets();
                Notification::make()->title('Entry voided')->success()->send();
            });
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
