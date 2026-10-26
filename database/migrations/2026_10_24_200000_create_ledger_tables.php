<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger (docs/PLAN.md §2, §5). A journal is one thing staff did; its lines
 * are the signed amounts it puts on accounts and P&L categories
 * (positive = debit). Balance of any account = opening + SUM(lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 10)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('type', 20);
            $table->foreignId('period_id')->constrained();
            $table->date('entry_date');
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->foreignId('company_account_id')->nullable()->constrained();
            $table->foreignId('member_id')->nullable()->constrained();
            $table->foreignId('member_bank_id')->nullable()->constrained('banks');
            $table->string('member_account_name')->nullable();
            $table->foreignId('bonus_type_id')->nullable()->constrained();
            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2)->default(0);
            $table->decimal('winlose', 20, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journals');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['type', 'entry_date']);
            $table->index(['company_account_id', 'entry_date']);
            $table->index(['brand_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $table->morphs('ledgerable');
            $table->foreignId('currency_id')->constrained();
            $table->decimal('amount', 20, 2);
            $table->timestamps();
        });

        Schema::create('balance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_account_id')->constrained();
            $table->decimal('actual_balance', 20, 2);
            $table->decimal('book_balance', 20, 2);
            $table->decimal('difference', 20, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('daily_panel_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->foreignId('brand_id')->constrained();
            $table->unsignedInteger('new_member')->nullable();
            $table->decimal('winlose', 20, 2)->nullable();
            $table->decimal('turnover', 20, 2)->nullable();
            $table->decimal('from_dp', 20, 2)->nullable();
            $table->decimal('form_bonus', 20, 2)->nullable();
            $table->decimal('from_wd', 20, 2)->nullable();
            $table->decimal('from_wd_manual', 20, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['report_date', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_panel_reports');
        Schema::dropIfExists('balance_checks');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('periods');
    }
};
