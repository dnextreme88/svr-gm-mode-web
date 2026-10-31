<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factions (house rule): named tag teams and stables of 2–5 wrestlers.
 *
 * `factions` / `faction_members` are the seeded catalog, with real-life
 * dates kept for display. Each save copies them into `save_factions` /
 * `save_faction_members`, where factions are formed and disbanded in game
 * weeks. `faction_prompts` holds the player's teams waiting to be named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factions', function (Blueprint $table) {
            $table->id();
            $table->string('faction_name')->unique();
            $table->string('faction_type', 20);
            $table->date('date_formed');
            $table->date('date_disbanded')->nullable();
            $table->timestamps();
        });

        Schema::create('faction_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wrestler_id')->constrained()->cascadeOnDelete();
            $table->unique(['faction_id', 'wrestler_id']);
        });

        Schema::create('save_factions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('faction_name');
            $table->string('faction_type', 20);
            $table->string('origin', 20);
            $table->string('brand', 20)->nullable();
            $table->unsignedInteger('formed_week')->default(0);
            $table->date('date_formed')->nullable();
            $table->unsignedInteger('record_from_week')->default(0);
            $table->unsignedInteger('disbanded_week')->nullable();
            $table->date('date_disbanded')->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'faction_name']);
            $table->index(['save_id', 'disbanded_week']);
        });

        Schema::create('save_faction_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('joined_week')->default(0);
            $table->unique(['save_faction_id', 'save_wrestler_id']);
        });

        Schema::create('faction_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('member_key', 100);
            $table->json('member_ids');
            $table->unsignedSmallInteger('wins');
            $table->unsignedInteger('first_win_week');
            $table->unsignedInteger('week_number');
            $table->foreignId('show_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('deferred_at_wins')->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'member_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faction_prompts');
        Schema::dropIfExists('save_faction_members');
        Schema::dropIfExists('save_factions');
        Schema::dropIfExists('faction_members');
        Schema::dropIfExists('factions');
    }
};
