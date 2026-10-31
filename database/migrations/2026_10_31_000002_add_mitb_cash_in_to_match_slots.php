<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money in the Bank cash-in timing (house rule): whether a match is a
 * cash-in placed before or after the card's matches, or a world title
 * match the holder joined during (`MitbCashInTiming`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_slots', function (Blueprint $table) {
            $table->string('mitb_cash_in', 10)->nullable()->after('contested_title_ids');
        });
    }

    public function down(): void
    {
        Schema::table('match_slots', function (Blueprint $table) {
            $table->dropColumn('mitb_cash_in');
        });
    }
};
