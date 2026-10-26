<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing snapshot taken when a month is closed (tutup buku): each
 * account's balance, kurs and Rp value at month end. Removed on reopen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_account_id')->constrained();
            $table->decimal('balance', 20, 2);
            $table->decimal('kurs', 18, 6)->nullable();
            $table->decimal('idr_value', 20, 2)->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'company_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_balances');
    }
};
