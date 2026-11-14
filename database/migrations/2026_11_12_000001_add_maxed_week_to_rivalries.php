<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rivalry heat (house rule, 2026-11-12): a rivalry that reaches 100 heat
 * ends the following week, so the week it maxed out is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rivalries', function (Blueprint $table) {
            $table->unsignedInteger('maxed_week')->nullable()->after('last_activity_week');
        });
    }

    public function down(): void
    {
        Schema::table('rivalries', function (Blueprint $table) {
            $table->dropColumn('maxed_week');
        });
    }
};
