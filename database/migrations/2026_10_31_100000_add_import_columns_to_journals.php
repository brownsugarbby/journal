<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows brought in from an Excel import (PGA export, weekly cashback):
     * import_key makes a second upload of the same file skip what is already
     * there; source_time / source_operator keep WAKTU and OP from the file.
     */
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->string('import_key', 64)->nullable()->unique()->after('note');
            $table->time('source_time')->nullable()->after('import_key');
            $table->string('source_operator', 50)->nullable()->after('source_time');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn(['import_key', 'source_time', 'source_operator']);
        });
    }
};
