<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A brand with more than one game panel gets one Daily Panel Report row
     * per panel per day; brands without panels keep one row per day (null).
     */
    public function up(): void
    {
        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->foreignId('game_panel_id')->nullable()->after('brand_id')->constrained();
            // MySQL backs the brand foreign key with this index: add the new
            // one before dropping it.
            $table->index('brand_id');
            $table->unique(['report_date', 'brand_id', 'game_panel_id'], 'daily_panel_reports_day_panel_unique');
        });

        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->dropUnique(['report_date', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->unique(['report_date', 'brand_id']);
        });

        Schema::table('daily_panel_reports', function (Blueprint $table) {
            $table->dropUnique('daily_panel_reports_day_panel_unique');
            $table->dropConstrainedForeignId('game_panel_id');
            $table->dropIndex(['brand_id']);
        });
    }
};
