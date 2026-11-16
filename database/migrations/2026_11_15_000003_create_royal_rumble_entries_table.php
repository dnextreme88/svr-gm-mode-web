<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House rule (2026-11-15): each Royal Rumble entrant's entry number,
 * eliminations and result, for the superstar profile's Special info.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('royal_rumble_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->foreignId('save_wrestler_id')->constrained('save_wrestlers')->cascadeOnDelete();
            $table->string('brand', 20)->nullable();
            $table->unsignedTinyInteger('entry_number');
            $table->unsignedTinyInteger('eliminations')->default(0);
            $table->foreignId('eliminated_by_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->unsignedTinyInteger('elimination_order')->nullable();
            $table->boolean('won')->default(false);
            $table->timestamps();
            $table->unique(['match_slot_id', 'save_wrestler_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('royal_rumble_entries');
    }
};
