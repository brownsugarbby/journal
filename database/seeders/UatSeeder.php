<?php

namespace Database\Seeders;

use App\Enums\FeeType;
use App\Enums\LoanCounterpartyType;
use App\Enums\PayColumnKind;
use App\Enums\PayType;
use App\Enums\Role;
use App\Models\BonusType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LoanCounterparty;
use App\Models\PayColumn;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Test data for the UAT checklist (docs/UAT.md). Local only: refuses to run
 * in production. Run after a fresh database:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=UatSeeder
 *
 * The expected numbers in docs/UAT.md start from exactly these values
 * (tests/Feature/UatScenarioTest.php checks them).
 */
class UatSeeder extends Seeder
{
    /**
     * Shared password of the UAT test users (local test data only).
     */
    public const PASSWORD = 'uat-test-2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('UatSeeder creates test users and must not run in production.');
        }

        $kes = Brand::query()->where('code', 'KES')->firstOrFail();
        $account = fn (string $label): CompanyAccount => CompanyAccount::query()->where('label', $label)->firstOrFail();
        $firstOfMonth = today()->startOfMonth()->toDateString();

        // Opening balances = "the sheets' SALDO at go-live".
        foreach (['BCA DP/WD' => 500_000, 'MDR DP/WD' => 300_000, 'HOKI KES' => 2_000_000, 'FLIPTECH' => 20_000_000, 'SEABANK' => 5_000_000] as $label => $opening) {
            $account($label)->update(['opening_balance' => $opening, 'opening_date' => $firstOfMonth]);
        }

        PaymentGateway::query()->where('code', 'HOKI')->firstOrFail()->update([
            'dp_fee_type' => FeeType::Flat, 'dp_fee_value' => 2_500,
            'wd_fee_type' => FeeType::Flat, 'wd_fee_value' => 2_500,
        ]);

        BonusType::query()->firstOrCreate(['name' => 'Bonus New Member', 'brand_id' => null], ['kind' => 'bonus']);

        // One user per role (the superadmin comes from SuperAdminSeeder / .env).
        $users = [
            ['leader1', 'Leader Satu', Role::Leader, [], [], false, false],
            ['admin.kes', 'Admin KES', Role::Admin, ['KES'], ['BCA DP/WD', 'HOKI KES'], true, true],
            ['cs.kes', 'CS KES', Role::StaffCs, ['KES'], ['BCA DP/WD', 'MDR DP/WD'], false, false],
        ];

        foreach ($users as [$username, $name, $role, $brands, $accounts, $expense, $panel]) {
            $user = User::query()->firstOrCreate(['username' => $username], [
                'name' => $name,
                'password' => self::PASSWORD,
                'can_enter_expense' => $expense,
                'can_enter_panel_report' => $panel,
            ]);
            $user->syncRoles([$role->value]);
            $user->brands()->sync(Brand::query()->whereIn('code', $brands)->pluck('id'));
            $user->companyAccounts()->sync(CompanyAccount::query()->whereIn('label', $accounts)->pluck('id'));
        }

        $cvc = Company::query()->where('short_code', 'CVC')->firstOrFail();
        $idr = Currency::query()->where('code', 'IDR')->firstOrFail();
        $thb = Currency::query()->where('code', 'THB')->firstOrFail();

        $andi = Employee::query()->firstOrCreate(['name' => 'Andi (UAT)'], [
            'company_id' => $cvc->id, 'position' => 'ADM', 'join_date' => today()->subYears(2)->startOfMonth(),
            'salary_amount' => 5_000_000, 'salary_currency_id' => $idr->id,
            'um_amount' => 1_500_000, 'um_currency_id' => $idr->id,
        ]);
        Employee::query()->firstOrCreate(['name' => 'Somchai (UAT)'], [
            'company_id' => $cvc->id, 'position' => 'CS', 'join_date' => today()->subMonths(8)->startOfMonth(),
            'salary_amount' => 4_000_000, 'salary_currency_id' => $idr->id,
            'um_amount' => 3_000, 'um_currency_id' => $thb->id,
        ]);

        LoanCounterparty::query()->firstOrCreate(['name' => 'Andi (UAT)'], [
            'type' => LoanCounterpartyType::Employee, 'employee_id' => $andi->id,
        ]);

        PayColumn::query()->firstOrCreate(['pay_type' => PayType::Gaji, 'name' => 'Lembur'], [
            'kind' => PayColumnKind::Addition,
            'category_id' => Category::query()->where('code', 'lembur_offday')->value('id'),
        ]);

        $this->command?->info('UAT data ready. Users leader1, admin.kes, cs.kes; password in UatSeeder::PASSWORD.');
        $this->command?->info("Brand used in the checklist: {$kes->code}.");
    }
}
