<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Who')
                    ->placeholder('System / guest'),
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge(),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'login_failed' => 'danger',
                        'created', 'login' => 'success',
                        'updated', 'role_assigned', 'password_reset' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state
                        ? class_basename($state).' #'.$record->subject_id
                        : '-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('causer_id')
                    ->label('Who')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $query, $id): Builder => $query
                            ->where('causer_type', (new User)->getMorphClass())
                            ->where('causer_id', $id),
                    )),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
