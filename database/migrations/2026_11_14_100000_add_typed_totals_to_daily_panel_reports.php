<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sheet's money columns typed by hand, for brands not entering every
     * DP / WD in the jurnal (yet). Empty = take the jurnal's figure.
     */
    public function up(): void
    {
        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->decimal('total_dp', 20, 2)->nullable()->after('new_member');
            $table->decimal('total_dp_pga', 20, 2)->nullable()->after('total_dp');
            $table->decimal('total_wd', 20, 2)->nullable()->after('total_dp_pga');
            $table->decimal('total_wd_manual', 20, 2)->nullable()->after('total_wd');
            $table->decimal('bonus_manual', 20, 2)->nullable()->after('from_wd_manual');
        });
    }

    public function down(): void
    {
        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->dropColumn(['total_dp', 'total_dp_pga', 'total_wd', 'total_wd_manual', 'bonus_manual']);
        });
    }
};
