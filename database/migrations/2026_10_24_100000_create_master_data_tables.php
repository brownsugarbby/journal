<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data (docs/PLAN.md §4). Rows are deactivated, never deleted, so
 * journals created later always keep a valid reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('short_name', 20)->nullable();
            $table->string('type', 20);
            $table->string('country', 2)->default('ID');
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('dashboard_url')->nullable();
            $table->foreignId('currency_id')->constrained();
            $table->string('dp_fee_type', 10)->default('flat');
            $table->decimal('dp_fee_value', 18, 4)->default(0);
            $table->string('wd_fee_type', 10)->default('flat');
            $table->decimal('wd_fee_value', 18, 4)->default(0);
            $table->string('wd_fee_paid_by', 10)->default('company');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_gateway_fee_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained()->cascadeOnDelete();
            $table->timestamp('effective_from');
            $table->string('dp_fee_type', 10);
            $table->decimal('dp_fee_value', 18, 4);
            $table->string('wd_fee_type', 10);
            $table->decimal('wd_fee_value', 18, 4);
            $table->string('wd_fee_paid_by', 10);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // Explicit name: the generated one is over MySQL's 64-character limit.
            $table->index(['payment_gateway_id', 'effective_from'], 'pga_fee_histories_gateway_from_index');
        });

        Schema::create('company_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->foreignId('bank_id')->nullable()->constrained();
            $table->foreignId('payment_gateway_id')->nullable()->constrained();
            $table->string('tier', 20);
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->string('label')->unique();
            $table->string('account_name')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('purpose', 10);
            $table->string('holder')->nullable();
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->decimal('max_balance', 20, 2)->nullable();
            $table->string('dp_fee_type', 10)->nullable();
            $table->decimal('dp_fee_value', 18, 4)->nullable();
            $table->string('wd_fee_type', 10)->nullable();
            $table->decimal('wd_fee_value', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('game_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained();
            $table->string('name');
            $table->string('panel_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bonus_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->string('name');
            $table->string('kind', 10);
            $table->decimal('default_percentage', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('pl_group', 20);
            $table->string('type', 10);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('show_headcount')->default(false);
            $table->string('source', 10)->default('manual');
            $table->string('source_key')->nullable()->unique();
            $table->foreignId('payment_gateway_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('profit_share_percentage', 5, 2)->nullable();
            $table->foreignId('currency_id')->constrained();
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('position')->nullable();
            $table->date('join_date')->nullable();
            $table->decimal('salary_amount', 20, 2)->default(0);
            $table->foreignId('salary_currency_id')->constrained('currencies');
            $table->decimal('um_amount', 20, 2)->default(0);
            $table->foreignId('um_currency_id')->constrained('currencies');
            $table->foreignId('bank_id')->nullable()->constrained();
            $table->string('account_number', 50)->nullable();
            $table->string('account_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_counterparties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->foreignId('employee_id')->nullable()->constrained();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained();
            $table->string('member_id', 100);
            $table->string('name')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained();
            $table->string('account_number', 50)->nullable();
            $table->string('account_name')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'member_id']);
        });

        Schema::create('pay_columns', function (Blueprint $table) {
            $table->id();
            $table->string('pay_type', 10);
            $table->string('name');
            $table->string('kind', 10);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 20)->default('gray');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brand_user', function (Blueprint $table) {
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['brand_id', 'user_id']);
        });

        Schema::create('company_account_user', function (Blueprint $table) {
            $table->foreignId('company_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['company_account_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_enter_expense')->default(false)->after('is_active');
            $table->boolean('can_enter_panel_report')->default(false)->after('can_enter_expense');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_enter_expense', 'can_enter_panel_report']);
        });

        foreach ([
            'company_account_user', 'brand_user', 'expense_statuses', 'pay_columns', 'members',
            'loan_counterparties', 'employees', 'partners', 'categories', 'bonus_types', 'game_panels',
            'company_accounts', 'payment_gateway_fee_histories', 'payment_gateways', 'banks',
            'currencies', 'brands', 'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
