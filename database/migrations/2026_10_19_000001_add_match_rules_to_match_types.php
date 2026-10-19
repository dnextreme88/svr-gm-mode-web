<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match rules house rules (2026-10-19): rope breaks, ring outs, Captain Fall
 * and Timed flags. The values come from the catalog seeder
 * (`php artisan migrate:fresh --seed`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_types', function (Blueprint $table) {
            $table->boolean('rope_breaks')->default(true)->after('countout_enabled');
            $table->boolean('ring_out')->default(true)->after('rope_breaks');
            $table->boolean('captain_fall')->default(false)->after('ring_out');
            $table->boolean('timed')->default(false)->after('captain_fall');
        });
    }

    public function down(): void
    {
        Schema::table('match_types', function (Blueprint $table) {
            $table->dropColumn(['rope_breaks', 'ring_out', 'captain_fall', 'timed']);
        });
    }
};
