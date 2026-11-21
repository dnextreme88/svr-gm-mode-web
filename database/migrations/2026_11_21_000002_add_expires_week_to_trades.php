<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rival GM trade offers (house rule, 2026-11-21): the rival proposes a trade
 * every few weeks, and the player has until this week to answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->unsignedInteger('expires_week')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('expires_week');
        });
    }
};
