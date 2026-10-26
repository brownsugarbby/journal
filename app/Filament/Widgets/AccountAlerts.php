<?php

namespace App\Filament\Widgets;

use App\Enums\AccountTier;
use App\Filament\Pages\Jurnal;
use App\Filament\Pages\Pusat;
use App\Models\BalanceCheck;
use App\Models\CompanyAccount;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Accounts that need someone: below zero, over their max balance (transfer
 * to pusat), or whose last Cek Saldo did not match. Empty = all good.
 */
class AccountAlerts extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Rekening perlu perhatian';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->alerts())
            ->columns([
                TextColumn::make('label')->label('Rekening')->weight('bold'),
                TextColumn::make('saldo')->label('Saldo')->alignEnd(),
                TextColumn::make('max')->label('Max')->alignEnd()->placeholder('-'),
                TextColumn::make('issue')->label('Masalah')->badge()->color('danger'),
            ])
            ->recordUrl(fn (array $record): ?string => $record['url'])
            ->paginated(false)
            ->emptyStateHeading('Semua rekening aman')
            ->emptyStateDescription('No account is over its max balance or has a Cek Saldo selisih.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function alerts(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $accounts = $user->hasFullAccess()
            ? CompanyAccount::query()->where('is_active', true)->orderBy('tier')->orderBy('label')->get()
            : $user->accessibleAccounts()->get();

        $lastChecks = BalanceCheck::query()
            ->whereIn('id', BalanceCheck::query()->selectRaw('max(id)')->whereIn('company_account_id', $accounts->pluck('id'))->groupBy('company_account_id'))
            ->get()
            ->keyBy('company_account_id');

        $alerts = [];

        foreach ($accounts as $account) {
            $balance = $account->balance();
            $issues = [];

            if (bccomp($balance, '0', 2) < 0) {
                $issues[] = 'Saldo minus';
            }

            if ($account->isOverMax($balance)) {
                $issues[] = 'Over max: transfer ke pusat';
            }

            $check = $lastChecks->get($account->id);

            if ($check && ! $check->isMatching()) {
                $issues[] = 'Selisih '.number_format((float) $check->difference, 0, ',', '.');
            }

            if ($issues === []) {
                continue;
            }

            $alerts["a{$account->id}"] = [
                'label' => $account->label,
                'saldo' => number_format((float) $balance, $account->currency?->decimals ?? 0, ',', '.'),
                'max' => $account->max_balance !== null ? number_format((float) $account->max_balance, 0, ',', '.') : null,
                'issue' => implode(' · ', $issues),
                'url' => $account->tier === AccountTier::Operational
                    ? Jurnal::getUrl(['tab' => "acc-{$account->id}"])
                    : ($user->hasFullAccess() ? Pusat::getUrl(['tab' => "acc-{$account->id}"]) : null),
            ];
        }

        return $alerts;
    }
}
