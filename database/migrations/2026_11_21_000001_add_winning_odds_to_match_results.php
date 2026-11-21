<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Winning odds (house rule, 2026-11-21): each side's chance going into a match,
 * with the modifiers behind it, kept on the result (and on each Gauntlet
 * bout) so the outcome card can show them after the match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_results', function (Blueprint $table) {
            $table->json('winning_odds')->nullable()->after('loser_score');
        });

        Schema::table('gauntlet_bouts', function (Blueprint $table) {
            $table->json('winning_odds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('match_results', function (Blueprint $table) {
            $table->dropColumn('winning_odds');
        });

        Schema::table('gauntlet_bouts', function (Blueprint $table) {
            $table->dropColumn('winning_odds');
        });
    }
};
