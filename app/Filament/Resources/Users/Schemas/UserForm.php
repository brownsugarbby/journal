<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccountTier;
use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->required()
                            ->regex('/^[A-Za-z0-9._-]+$/')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (string $state): string => strtolower($state))
                            ->helperText('Used to log in. Letters, numbers, dot, dash and underscore.'),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ]),
                Section::make('Access')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            // Only a superadmin can hand out the superadmin role.
                            ->options(fn (): array => collect(Role::cases())
                                ->filter(fn (Role $role): bool => $role !== Role::SuperAdmin || (auth()->user()?->can('grantSuperAdmin', User::class) ?? false))
                                ->mapWithKeys(fn (Role $role): array => [$role->value => $role->getLabel()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live()
                            // A superadmin can't demote themselves and lock everyone out.
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                            ->dehydrated(fn (?User $record): bool => ! ($record?->is(auth()->user()) ?? false)),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Inactive users cannot log in. Users are never deleted.')
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule(Password::min(8))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : null),
                    ]),
                Section::make('Scope')
                    ->description('Which brands and operational accounts this user may post on. Superadmins always see everything. Staff CS only enter DP and WD.')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => Role::fromState($get('role'))?->isScoped() ?? false)
                    ->schema([
                        Select::make('brands')
                            ->relationship('brands', 'code', fn (Builder $query) => $query->where('is_active', true))
                            ->multiple()
                            ->preload(),
                        Select::make('companyAccounts')
                            ->label('Accounts')
                            ->relationship(
                                'companyAccounts',
                                'label',
                                fn (Builder $query) => $query->where('is_active', true)->where('tier', AccountTier::Operational),
                            )
                            ->multiple()
                            ->preload(),
                        Toggle::make('can_enter_expense')
                            ->label('Can enter office expenses')
                            ->inline(false)
                            ->visible(fn (Get $get): bool => Role::fromState($get('role')) === Role::Admin),
                        Toggle::make('can_enter_panel_report')
                            ->label('Can enter daily panel report')
                            ->inline(false)
                            ->visible(fn (Get $get): bool => Role::fromState($get('role')) === Role::Admin),
                    ]),
            ]);
    }
}
