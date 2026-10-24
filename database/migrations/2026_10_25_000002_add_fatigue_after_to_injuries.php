<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries are judged on fatigue right after the match (house rule,
 * 2026-10-25). Fatigue is capped at 100%, so the uncapped total that caused
 * the injury (e.g. 110%) is kept here for the results screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('injuries', function (Blueprint $table) {
            $table->unsignedSmallInteger('fatigue_after')->nullable()->after('match_slot_id');
        });
    }

    public function down(): void
    {
        Schema::table('injuries', function (Blueprint $table) {
            $table->dropColumn('fatigue_after');
        });
    }
};
