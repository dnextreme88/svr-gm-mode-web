<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Conditional match stories (house rule, 2026-11-07): every time one plays
| out. Holds the cooldowns and once-per-rivalry memory, the story's text, and
| whether the player has seen its pop-up on the live results screen.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditional_story_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('story', 40);
            $table->string('outcome', 20);
            $table->unsignedInteger('season');
            $table->unsignedInteger('week_number');
            $table->foreignId('match_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rivalry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('save_faction_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tokens');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'story', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conditional_story_triggers');
    }
};
