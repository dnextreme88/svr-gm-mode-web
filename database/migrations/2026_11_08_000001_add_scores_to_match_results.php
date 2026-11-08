<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timed matches (house rule, 2026-11-08): an Iron Man or Ultimate Submission
 * ends on a score when time runs out, such as 3–1. Other matches have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_results', function (Blueprint $table) {
            $table->unsignedTinyInteger('winner_score')->nullable();
            $table->unsignedTinyInteger('loser_score')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('match_results', function (Blueprint $table) {
            $table->dropColumn(['winner_score', 'loser_score']);
        });
    }
};
