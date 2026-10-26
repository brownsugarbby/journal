<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'brands']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('role')
                    ->state(fn (User $record): ?Role => $record->role())
                    ->badge(),
                TextColumn::make('brands.code')
                    ->label('Brands')
                    ->badge()
                    ->placeholder(fn (User $record): string => $record->hasFullAccess() ? 'All' : 'None'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(Role::class)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $query, string $role): Builder => $query->role($role),
                    )),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record): Heroicon => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->hidden(fn (User $record): bool => $record->is(auth()->user()) || ! (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (User $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->defaultSort('name');
    }
}
