<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards, results and rivalries (§4, §5, §11, §20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('match_type_id')->nullable()->constrained();
            $table->foreignId('title_championship_id')->nullable()->constrained('championships');
            $table->string('stipulation', 40)->nullable();
            $table->unsignedInteger('cost')->default(0);
            $table->string('fixed_kind', 40)->nullable();
            $table->string('owner_brand', 20)->nullable();
            $table->decimal('rating', 4, 2)->nullable();
            $table->integer('fan_delta')->nullable();
            $table->timestamps();
            $table->unique(['show_id', 'position']);
        });

        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('side');
            $table->string('role', 20);
            $table->string('result', 10)->nullable();
            $table->unsignedTinyInteger('fatigue_before')->nullable();
            $table->timestamps();
            $table->index(['save_wrestler_id', 'role']);
        });

        Schema::create('match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_slot_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('winner_side');
            $table->string('method', 20);
            $table->string('finish_event', 30)->nullable();
            $table->foreignId('interferer_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->boolean('title_changed')->default(false);
            $table->string('title_banner', 20)->nullable();
            $table->string('stipulation_outcome')->nullable();
            $table->text('summary');
            $table->unsignedInteger('rng_seed_step');
            $table->timestamps();
        });

        Schema::create('promo_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('promo_type_id')->nullable()->constrained();
            $table->foreignId('target_wrestler_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->foreignId('target_wrestler_b_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->integer('cost_or_profit')->nullable();
            $table->boolean('succeeded')->nullable();
            $table->boolean('backfired')->default(false);
            $table->string('outcome_note')->nullable();
            $table->timestamps();
            $table->unique(['show_id', 'position']);
        });

        Schema::create('rivalries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wrestler_a_id')->constrained('save_wrestlers')->cascadeOnDelete();
            $table->foreignId('wrestler_b_id')->constrained('save_wrestlers')->cascadeOnDelete();
            $table->unsignedTinyInteger('heat');
            $table->unsignedInteger('weeks_active')->default(0);
            $table->unsignedInteger('started_week');
            $table->unsignedInteger('last_activity_week');
            $table->unsignedInteger('ended_week')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['save_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rivalries');
        Schema::dropIfExists('promo_slots');
        Schema::dropIfExists('match_results');
        Schema::dropIfExists('match_participants');
        Schema::dropIfExists('match_slots');
    }
};
