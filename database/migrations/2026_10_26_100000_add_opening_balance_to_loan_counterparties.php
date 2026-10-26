<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loans already outstanding at go-live: > 0 they owe us, < 0 we owe them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_counterparties', function (Blueprint $table) {
            $table->decimal('opening_balance', 20, 2)->default(0)->after('interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('loan_counterparties', function (Blueprint $table) {
            $table->dropColumn('opening_balance');
        });
    }
};
