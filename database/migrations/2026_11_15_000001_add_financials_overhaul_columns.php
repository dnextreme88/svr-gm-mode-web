<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financials overhaul (2026-11-15): per-superstar salaries, ledger lines
 * grouped by show night, a rivalry's brand and a Call-out promo's
 * alignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wrestlers', function (Blueprint $table) {
            // A month's salary; a contract costs this × its months.
            $table->unsignedInteger('monthly_salary')->default(20_000)->after('placeholder_brand');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            // The show night a line belongs to (null: week-wide).
            $table->unsignedBigInteger('show_id')->nullable()->after('week_number');
        });

        Schema::table('rivalries', function (Blueprint $table) {
            $table->string('brand', 20)->nullable()->after('save_id');
        });

        Schema::table('promo_slots', function (Blueprint $table) {
            // Call-out promo: face or heel.
            $table->string('alignment', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('promo_slots', fn (Blueprint $table) => $table->dropColumn('alignment'));
        Schema::table('rivalries', fn (Blueprint $table) => $table->dropColumn('brand'));
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropColumn('show_id'));
        Schema::table('wrestlers', fn (Blueprint $table) => $table->dropColumn('monthly_salary'));
    }
};
