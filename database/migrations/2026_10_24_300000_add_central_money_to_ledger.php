<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central money (docs/PLAN.md Phase 3): transfers, currency purchase, office
 * expenses, loans, partners and adjustments.
 *
 * Every line now also carries its value in the base currency (IDR), so a
 * journal mixing IDR and THB/USD still balances: Σ idr_amount = 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->decimal('idr_amount', 20, 2)->nullable()->after('amount');
        });

        DB::table('journal_lines')->update(['idr_amount' => DB::raw('amount')]);

        Schema::table('journals', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('type');
            $table->foreignId('target_account_id')->nullable()->after('company_account_id')->constrained('company_accounts');
            $table->foreignId('category_id')->nullable()->after('bonus_type_id')->constrained();
            $table->foreignId('expense_status_id')->nullable()->after('category_id')->constrained();
            $table->foreignId('loan_counterparty_id')->nullable()->after('expense_status_id')->constrained();
            $table->foreignId('partner_id')->nullable()->after('loan_counterparty_id')->constrained();
            $table->foreignId('company_id')->nullable()->after('brand_id')->constrained();
            $table->decimal('foreign_amount', 20, 2)->nullable()->after('fee');
            $table->decimal('kurs', 18, 6)->nullable()->after('foreign_amount');
        });

        Schema::table('company_accounts', function (Blueprint $table) {
            $table->decimal('opening_kurs', 18, 6)->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('company_accounts', function (Blueprint $table) {
            $table->dropColumn('opening_kurs');
        });

        Schema::table('journals', function (Blueprint $table) {
            foreach (['target_account_id', 'category_id', 'expense_status_id', 'loan_counterparty_id', 'partner_id', 'company_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['kind', 'foreign_amount', 'kurs']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('idr_amount');
        });
    }
};
