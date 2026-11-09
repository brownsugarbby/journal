<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * - The seeded expense statuses get Indonesian names (only if still the
     *   seeded English name, so H D's own renames stay).
     * - Beli Dollar leaves the P&L: buying dollars swaps rupiah for dollars
     *   (Kas Pusat → DOLLAR → Beli valas); the cost is booked when the dollars
     *   are spent (e.g. Perpanjang Live Chat paid from DOLLAR), so counting the
     *   purchase too would count it twice (Q21).
     */
    public function up(): void
    {
        foreach (['Pending' => 'Belum dibayar', 'Paid' => 'Lunas'] as $old => $new) {
            if (! DB::table('expense_statuses')->where('name', $new)->exists()) {
                DB::table('expense_statuses')->where('name', $old)->update(['name' => $new]);
            }
        }

        DB::table('categories')->where('code', 'beli_dollar')->update(['pl_group' => 'not_in_pl', 'is_active' => false]);
    }

    public function down(): void
    {
        foreach (['Pending' => 'Belum dibayar', 'Paid' => 'Lunas'] as $old => $new) {
            DB::table('expense_statuses')->where('name', $new)->update(['name' => $old]);
        }

        DB::table('categories')->where('code', 'beli_dollar')->update(['pl_group' => 'biaya_tetap', 'is_active' => true]);
    }
};
