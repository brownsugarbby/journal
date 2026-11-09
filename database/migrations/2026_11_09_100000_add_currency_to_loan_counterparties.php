<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A loan can be in dollars or baht: its balance is then kept in that
     * currency (empty = rupiah, as before).
     */
    public function up(): void
    {
        Schema::table('loan_counterparties', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('type')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('loan_counterparties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });
    }
};
