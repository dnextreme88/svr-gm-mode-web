<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-save game state: saves, brands, seasons, roster, contracts, the draft,
 * shows, finances, emails and news (§1, §2, §7, §11, §19).
 *
 * `week_number` is absolute across a save (season 2 starts at week 53), so
 * week resolution is idempotent per (save_id, week_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('player_brand', 20);
            $table->string('rng_seed', 64);
            $table->string('stage', 30);
            $table->unsignedSmallInteger('current_season')->default(1);
            $table->unsignedInteger('current_week')->default(1);
            $table->json('draft_order');
            $table->timestamps();
        });

        Schema::create('save_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 20);
            $table->bigInteger('budget')->default(0);
            $table->boolean('is_human');
            $table->boolean('draft_done')->default(false);
            $table->unsignedSmallInteger('deficit_weeks')->default(0);
            $table->string('deficit_choice', 30)->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'brand']);
        });

        Schema::create('save_wrestlers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wrestler_id')->constrained();
            $table->string('brand', 20)->nullable();
            $table->string('morale', 20)->default('happy');
            $table->unsignedTinyInteger('fatigue')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('win_streak')->default(0);
            $table->unsignedInteger('loss_streak')->default(0);
            $table->unsignedInteger('weeks_unused')->default(0);
            $table->unsignedInteger('weeks_since_title_match')->default(0);
            $table->unsignedInteger('unbookable_until_week')->nullable();
            $table->unsignedInteger('unsignable_until_week')->nullable();
            $table->unsignedInteger('joined_brand_week')->nullable();
            $table->unsignedInteger('debuted_week')->nullable();
            $table->integer('popularity')->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'wrestler_id']);
            $table->index(['save_id', 'brand']);
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->unsignedInteger('started_week');
            $table->unsignedInteger('ended_week')->nullable();
            $table->bigInteger('starting_budget')->nullable();
            $table->foreignId('royal_rumble_winner_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->timestamps();
            $table->unique(['save_id', 'number']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 20);
            $table->unsignedTinyInteger('length_weeks');
            $table->unsignedInteger('upfront_fee');
            $table->unsignedInteger('weekly_cost');
            $table->unsignedInteger('signed_week');
            $table->unsignedInteger('starts_week');
            $table->unsignedSmallInteger('weeks_remaining');
            $table->unsignedInteger('last_ticked_week')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('ended_week')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'status']);
        });

        Schema::create('draft_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('phase', 30);
            $table->unsignedSmallInteger('pick_number');
            $table->string('brand', 20);
            $table->foreignId('save_wrestler_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);
            $table->string('pick_type', 20);
            $table->boolean('skipped')->default(false);
            $table->timestamps();
            $table->unique(['save_id', 'season', 'phase', 'pick_number']);
        });

        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->string('brand', 20)->nullable();
            $table->string('show_type', 20);
            $table->foreignId('ppv_event_id')->nullable()->constrained();
            $table->string('name');
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['save_id', 'week_number']);
        });

        Schema::create('week_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->unsignedSmallInteger('season');
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'week_number']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 20);
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->string('category', 40);
            $table->bigInteger('amount');
            $table->string('memo');
            $table->nullableMorphs('subject');
            $table->timestamps();
            $table->index(['save_id', 'brand', 'week_number']);
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 20);
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->string('template', 40);
            $table->string('sender');
            $table->string('subject');
            $table->text('body')->nullable();
            $table->json('payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'brand', 'week_number']);
        });

        Schema::create('news_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->string('type', 40);
            $table->string('tier', 20);
            $table->string('brand', 20);
            $table->integer('priority')->default(0);
            $table->json('subject_ids');
            $table->json('payload');
            $table->text('rendered_text');
            $table->string('dedupe_key')->nullable();
            $table->foreignId('related_email_id')->nullable()->constrained('emails')->nullOnDelete();
            $table->timestamps();
            $table->index(['save_id', 'week_number']);
        });

        Schema::create('tag_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tag_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->unique(['tag_team_id', 'save_wrestler_id']);
        });

        Schema::create('title_reigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained();
            $table->string('brand', 20);
            $table->unsignedInteger('reign_number')->nullable();
            $table->string('holder_type', 20);
            $table->foreignId('save_wrestler_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tag_team_id')->nullable()->constrained()->nullOnDelete();
            $table->json('holder_snapshot');
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('won_week');
            $table->foreignId('won_show_id')->nullable()->constrained('shows')->nullOnDelete();
            $table->unsignedBigInteger('won_match_id')->nullable();
            $table->string('won_via', 20)->nullable();
            $table->unsignedInteger('lost_week')->nullable();
            $table->foreignId('lost_show_id')->nullable()->constrained('shows')->nullOnDelete();
            $table->unsignedBigInteger('lost_match_id')->nullable();
            $table->string('end_reason', 30);
            $table->unsignedInteger('defenses')->default(0);
            $table->timestamps();
            $table->index(['save_id', 'championship_id', 'end_reason']);
        });

        Schema::create('money_in_the_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('brand', 20);
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('won_week');
            $table->unsignedInteger('expires_week');
            $table->string('status', 20);
            $table->unsignedBigInteger('cash_in_match_id')->nullable();
            $table->unsignedInteger('resolved_week')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();
        });

        Schema::create('morale_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('level', 20);
            $table->unsignedInteger('opened_week');
            $table->unsignedInteger('resolved_week')->nullable();
            $table->timestamps();
            $table->index(['save_wrestler_id', 'resolved_week']);
        });

        Schema::create('pending_roster_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 20);
            $table->unsignedInteger('effective_week');
            $table->unsignedInteger('applied_week')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pending_roster_moves', 'morale_complaints', 'money_in_the_bank', 'title_reigns', 'tag_team_members', 'tag_teams', 'news_events', 'emails', 'ledger_entries', 'week_resolutions', 'shows', 'draft_picks', 'contracts', 'seasons', 'save_wrestlers', 'save_brands', 'saves'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
