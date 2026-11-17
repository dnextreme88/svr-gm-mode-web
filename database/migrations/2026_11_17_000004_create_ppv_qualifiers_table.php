<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qualifying Matches (house rule, 2026-11-17): who won a spot on their
     * brand's Survivor Series team or Money in the Bank ladder, one row per
     * qualifier. A spot is vacated when they're seriously injured or gone
     * from the brand by the PPV.
     */
    public function up(): void
    {
        Schema::create('ppv_qualifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('ppv', 40);
            $table->string('brand', 20);
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->unsignedInteger('vacated_week')->nullable();
            $table->string('vacated_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['save_id', 'season', 'ppv', 'brand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppv_qualifiers');
    }
};
