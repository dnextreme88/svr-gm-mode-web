<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rival GM (house rule, 2026-11-13): its stipulation plans (roll due weeks,
 * won rolls still to book) and the side it books to win a match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('save_brands', function (Blueprint $table) {
            $table->json('ai_plans')->nullable();
        });

        Schema::table('match_slots', function (Blueprint $table) {
            $table->unsignedTinyInteger('booked_side')->nullable()->after('owner_brand');
        });
    }

    public function down(): void
    {
        Schema::table('save_brands', function (Blueprint $table) {
            $table->dropColumn('ai_plans');
        });

        Schema::table('match_slots', function (Blueprint $table) {
            $table->dropColumn('booked_side');
        });
    }
};
