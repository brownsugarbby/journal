<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll (docs/PLAN.md Phase 4): one pay run per month and pay type (Gaji,
 * UM), one item per employee in the employee's currency, plus the values of
 * H D's own pay columns. Paying a run posts one journal per currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->string('pay_type', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('pay_date');
            $table->string('status', 10)->default('draft');
            // Kurs per currency code used when paid, e.g. {"THB": "470.000000"}.
            $table->json('kurs')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['pay_type', 'year', 'month']);
        });

        Schema::create('pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->decimal('gross', 20, 2)->default(0);
            $table->decimal('potong', 20, 2)->default(0);
            $table->decimal('loan_repayment', 20, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['pay_run_id', 'employee_id']);
        });

        Schema::create('pay_item_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_column_id')->constrained();
            $table->decimal('value', 20, 2)->nullable();
            $table->string('text')->nullable();
            $table->timestamps();

            $table->unique(['pay_item_id', 'pay_column_id']);
        });

        Schema::table('pay_columns', function (Blueprint $table) {
            // Additions / deductions booked on their own P&L line (e.g. Lembur Offday).
            $table->foreignId('category_id')->nullable()->after('kind')->constrained();
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->foreignId('pay_run_id')->nullable()->after('partner_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_run_id');
        });

        Schema::table('pay_columns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('pay_item_values');
        Schema::dropIfExists('pay_items');
        Schema::dropIfExists('pay_runs');
    }
};
