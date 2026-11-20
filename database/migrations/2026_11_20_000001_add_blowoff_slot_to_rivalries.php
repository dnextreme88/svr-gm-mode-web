<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rival GM blow-offs (house rule, 2026-11-20): the rival GM ends a hot
 * rivalry by booking its rivals 1v1 at its next PPV (or the weekly show
 * after it). The match it booked is kept on the rivalry, which concludes
 * once that match has aired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rivalries', function (Blueprint $table) {
            $table->foreignId('blowoff_slot_id')->nullable()->after('maxed_week')
                ->constrained('match_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rivalries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blowoff_slot_id');
        });
    }
};
