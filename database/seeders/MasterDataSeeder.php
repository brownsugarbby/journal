<?php

namespace Database\Seeders;

use App\Enums\AccountKind;
use App\Enums\AccountPurpose;
use App\Enums\AccountTier;
use App\Enums\BankType;
use App\Enums\BonusKind;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\PlGroup;
use App\Models\Bank;
use App\Models\BonusType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\ExpenseStatus;
use App\Models\Partner;
use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

/**
 * Known values from H D's sheets. Idempotent: rows are matched on their
 * unique key and never overwritten, so it is safe to re-run after edits.
 * Account numbers, opening balances and fees are placeholders to fill in.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->companies();
        $this->brands();
        $this->currencies();
        $this->banks();
        $this->categories();
        $this->paymentGateways();
        $this->accounts();
        $this->bonusTypes();
        $this->partners();
        $this->expenseStatuses();
    }

    private function companies(): void
    {
        Company::query()->firstOrCreate(['short_code' => 'CVC'], ['name' => 'CVC']);
    }

    private function brands(): void
    {
        foreach (['KES' => 'KES', 'JALU' => 'JALU'] as $code => $name) {
            Brand::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    private function currencies(): void
    {
        $currencies = [
            ['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'decimals' => 0, 'is_base' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_base' => false],
            ['code' => 'THB', 'name' => 'Baht', 'symbol' => '฿', 'decimals' => 2, 'is_base' => false],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->firstOrCreate(['code' => $currency['code']], $currency);
        }
    }

    private function banks(): void
    {
        $banks = [
            ['code' => 'BCA', 'name' => 'BCA', 'short_name' => 'BCA', 'type' => BankType::Bank],
            ['code' => 'MANDIRI', 'name' => 'Mandiri', 'short_name' => 'MDR', 'type' => BankType::Bank],
            ['code' => 'BRI', 'name' => 'BRI', 'short_name' => 'BRI', 'type' => BankType::Bank],
            ['code' => 'BNI', 'name' => 'BNI', 'short_name' => 'BNI', 'type' => BankType::Bank],
            ['code' => 'CIMB', 'name' => 'CIMB Niaga', 'short_name' => 'CIMB', 'type' => BankType::Bank],
            ['code' => 'SEABANK', 'name' => 'SeaBank', 'short_name' => 'SEABANK', 'type' => BankType::Bank],
            ['code' => 'ALADIN', 'name' => 'Bank Aladin', 'short_name' => 'ALADIN', 'type' => BankType::Bank],
            ['code' => 'DANA', 'name' => 'DANA', 'short_name' => 'DANA', 'type' => BankType::EWallet],
            ['code' => 'FLIP', 'name' => 'Flip (Fliptech)', 'short_name' => 'FLIPTECH', 'type' => BankType::EWallet],
            ['code' => 'ABA', 'name' => 'ABA Bank', 'short_name' => 'ABA', 'type' => BankType::Bank, 'country' => 'KH'],
        ];

        foreach ($banks as $bank) {
            Bank::query()->firstOrCreate(['code' => $bank['code']], $bank);
        }
    }

    /**
     * P&L lines in Hitung Bersih order. PGA fee lines (sort 160+) are added
     * automatically per payment gateway.
     */
    private function categories(): void
    {
        $income = CategoryType::Income;
        $expense = CategoryType::Expense;

        $categories = [
            // [code, name, group, type, sort, source_key (auto), headcount]
            ['pendapatan_kotor', 'Pendapatan Kotor (DP - WD)', PlGroup::Pendapatan, $income, 10, 'journal.gross_revenue', false],
            ['pendapatan_lain', 'Pendapatan Lain', PlGroup::Pendapatan, $income, 20, null, false],

            ['akuran', 'Akuran', PlGroup::BiayaTetap, $expense, 100, null, false],
            ['konsor_idn', 'Konsor IDN', PlGroup::BiayaTetap, $expense, 110, null, false],
            ['gaji_staff', 'Gaji Staff', PlGroup::BiayaTetap, $expense, 120, 'payroll.gaji', true],
            ['um', 'UM', PlGroup::BiayaTetap, $expense, 130, 'payroll.um', true],
            ['beli_dollar', 'Beli Dollar', PlGroup::BiayaTetap, $expense, 140, null, false],
            ['tagihan_mess', 'Tagihan Mess, Listrik & Air', PlGroup::BiayaTetap, $expense, 150, null, false],
            ['biaya_adm', 'Biaya ADM', PlGroup::BiayaTetap, $expense, 300, null, false],
            ['bunga', 'Bunga', PlGroup::BiayaTetap, $expense, 310, 'loan.interest', false],

            ['biaya_marketing', 'Biaya Marketing', PlGroup::BiayaVariable, $expense, 400, null, false],
            ['bank_ppatk_blokir', 'Bank PPATK/Blokir', PlGroup::BiayaVariable, $expense, 410, null, false],
            ['lembur_offday', 'Lembur Offday', PlGroup::BiayaVariable, $expense, 420, null, true],
            ['perpanjang_rekening', 'Perpanjang Rekening', PlGroup::BiayaVariable, $expense, 430, null, false],
            ['perpanjang_live_chat', 'Perpanjang Live Chat', PlGroup::BiayaVariable, $expense, 440, null, false],
            ['bayar_utang_pindahan', 'Bayar Utang Pindahan', PlGroup::BiayaVariable, $expense, 450, null, false],
            ['operasional_lainnya', 'Biaya Operasional Lainnya', PlGroup::BiayaVariable, $expense, 460, null, false],

            // Not on the sheet's P&L yet (open question Q22).
            ['bonus_member', 'Bonus Member', PlGroup::NotInPl, $expense, 900, 'journal.bonus', false],
            ['cashback_member', 'Cashback Member', PlGroup::NotInPl, $expense, 910, 'journal.cashback', false],
            ['selisih_kurs', 'Selisih Kurs', PlGroup::NotInPl, $expense, 920, 'fx.difference', false],
            ['penyesuaian_saldo', 'Penyesuaian Saldo', PlGroup::NotInPl, $expense, 930, 'journal.adjustment', false],
        ];

        foreach ($categories as [$code, $name, $group, $type, $sort, $sourceKey, $headcount]) {
            Category::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'pl_group' => $group,
                'type' => $type,
                'sort_order' => $sort,
                'source' => $sourceKey ? CategorySource::Auto : CategorySource::Manual,
                'source_key' => $sourceKey,
                'show_headcount' => $headcount,
            ]);
        }
    }

    private function paymentGateways(): void
    {
        // Fees unknown yet (open question Q1/Q18): 0 until H D confirms.
        PaymentGateway::query()->firstOrCreate(['code' => 'HOKI'], [
            'name' => 'HOKI',
            'currency_id' => $this->currencyId('IDR'),
        ]);
    }

    private function accounts(): void
    {
        $operationalMax = 1_000_000;
        $pusatMax = 50_000_000;

        // Operational bank accounts from the DP/WD tabs (shared by all brands).
        foreach (['BCA DP/WD' => 'BCA', 'MDR DP/WD' => 'MANDIRI', 'BRI DP/WD' => 'BRI', 'BNI DP/WD' => 'BNI', 'DANA DP/WD' => 'DANA'] as $label => $bank) {
            $this->account($label, [
                'kind' => AccountKind::Bank,
                'bank_id' => $this->bankId($bank),
                'tier' => AccountTier::Operational,
                'purpose' => AccountPurpose::DepositWithdraw,
                'max_balance' => $operationalMax,
            ]);
        }

        // PGA HOKI tabs, one per brand.
        foreach (['HOKI KES' => 'KES', 'HOKI JALU' => 'JALU'] as $label => $brand) {
            $this->account($label, [
                'kind' => AccountKind::Gateway,
                'payment_gateway_id' => PaymentGateway::query()->where('code', 'HOKI')->value('id'),
                'tier' => AccountTier::Operational,
                'brand_id' => Brand::query()->where('code', $brand)->value('id'),
                'purpose' => AccountPurpose::DepositWithdraw,
            ]);
        }

        // Saldo Pusat tabs.
        foreach (['FLIPTECH' => 'FLIP', 'SEABANK' => 'SEABANK', 'CIMB NIAGA' => 'CIMB', 'ALADIN' => 'ALADIN'] as $label => $bank) {
            $this->account($label, [
                'kind' => AccountKind::Bank,
                'bank_id' => $this->bankId($bank),
                'tier' => AccountTier::Pusat,
                'purpose' => AccountPurpose::Central,
                'holder' => 'FUNG',
                'max_balance' => $pusatMax,
            ]);
        }

        $this->account('DOLLAR (ABA)', [
            'kind' => AccountKind::Bank,
            'bank_id' => $this->bankId('ABA'),
            'currency_id' => $this->currencyId('USD'),
            'tier' => AccountTier::Pusat,
            'purpose' => AccountPurpose::Central,
            'holder' => 'KO HD',
        ]);

        // Baht bought at the money changer (for UM in BAHT).
        $this->account('CASH THB', [
            'kind' => AccountKind::Cash,
            'currency_id' => $this->currencyId('THB'),
            'tier' => AccountTier::Pusat,
            'purpose' => AccountPurpose::Cash,
        ]);
    }

    private function bonusTypes(): void
    {
        BonusType::query()->firstOrCreate(
            ['name' => 'Cashback', 'brand_id' => null],
            ['kind' => BonusKind::Cashback, 'default_percentage' => 5],
        );
    }

    private function partners(): void
    {
        foreach (['Ko TM', 'Ko HD'] as $name) {
            Partner::query()->firstOrCreate(['name' => $name], ['currency_id' => $this->currencyId('IDR')]);
        }
    }

    private function expenseStatuses(): void
    {
        // Placeholders until H D confirms the STATUS values (Q8).
        foreach ([['Pending', 'warning', 10], ['Paid', 'success', 20]] as [$name, $color, $sort]) {
            ExpenseStatus::query()->firstOrCreate(['name' => $name], ['color' => $color, 'sort_order' => $sort]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(string $label, array $attributes): void
    {
        CompanyAccount::query()->firstOrCreate(['label' => $label], [
            'currency_id' => $this->currencyId('IDR'),
            'opening_balance' => 0,
            'opening_date' => now()->startOfMonth(),
            ...$attributes,
        ]);
    }

    private function bankId(string $code): int
    {
        return Bank::query()->where('code', $code)->value('id');
    }

    private function currencyId(string $code): int
    {
        return Currency::query()->where('code', $code)->value('id');
    }
}
