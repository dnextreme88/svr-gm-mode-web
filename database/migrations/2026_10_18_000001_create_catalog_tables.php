<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seeded lookup tables shared by every save (§9–14, §21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wrestlers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('roster_type', 20);
            $table->string('weight_class', 30);
            $table->unsignedSmallInteger('billed_weight_lb')->nullable();
            $table->string('placeholder_brand', 20)->nullable();
            $table->unsignedTinyInteger('overall')->nullable();
            $table->string('hometown')->nullable();
            $table->timestamps();
        });

        Schema::create('wrestler_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wrestler_id')->constrained()->cascadeOnDelete();
            $table->string('family', 40);
            $table->unique(['wrestler_id', 'family']);
        });

        Schema::create('injury_types', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->string('severity', 20);
            $table->unsignedTinyInteger('min_weeks');
            $table->unsignedTinyInteger('max_weeks');
            $table->foreignId('escalates_to_id')->nullable()->constrained('injury_types')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ppv_events', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('brand_scope', 20);
            $table->string('month', 20);
            $table->unsignedTinyInteger('season_week');
            $table->timestamps();
        });

        Schema::create('match_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('family', 40);
            $table->string('variant');
            $table->json('sides');
            $table->json('manager_sides');
            $table->unsignedTinyInteger('min_competitors');
            $table->unsignedTinyInteger('max_competitors');
            $table->unsignedTinyInteger('fatigue_cost_bars');
            $table->unsignedInteger('cost');
            $table->boolean('dq_enabled');
            $table->boolean('countout_enabled');
            $table->boolean('divas_only')->default(false);
            $table->boolean('divas_allowed')->default(true);
            $table->boolean('titles_allowed')->default(true);
            $table->boolean('is_elimination')->default(false);
            $table->boolean('ppv_only')->default(false);
            $table->foreignId('ppv_exclusive_id')->nullable()->constrained('ppv_events')->nullOnDelete();
            $table->string('brand_restriction', 20)->nullable();
            $table->boolean('gm_bookable')->default(true);
            $table->string('win_condition')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['family', 'variant']);
        });

        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('brand', 20);
            $table->string('eligibility', 40);
            $table->boolean('is_tag')->default(false);
            $table->boolean('is_world')->default(false);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
        });

        Schema::create('promo_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->integer('base_amount');
            $table->boolean('targets_rival')->default(false);
            $table->boolean('variable_amount')->default(false);
            $table->boolean('needs_wrestler')->default(false);
            $table->boolean('needs_rival_pair')->default(false);
            $table->string('effect');
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_types');
        Schema::dropIfExists('championships');
        Schema::dropIfExists('match_types');
        Schema::dropIfExists('ppv_events');
        Schema::dropIfExists('injury_types');
        Schema::dropIfExists('wrestler_specialties');
        Schema::dropIfExists('wrestlers');
    }
};
