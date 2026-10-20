<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking order: the player confirms each of the week's cards in date
 * order (the Sunday PPV, then the weekly show). A confirmed card is
 * read-only for the player; the rival GM can still book its own slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
