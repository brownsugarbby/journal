<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why POTONG is what it is: one line per reason (absen, terlambat, denda…).
     * pay_items.potong stays the total, so payroll maths is unchanged.
     */
    public function up(): void
    {
        Schema::create('pay_item_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->decimal('amount', 20, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_item_deductions');
    }
};
