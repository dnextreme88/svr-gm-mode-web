<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Title contention (house rule): Winner Takes All and #1 Contender's Match.
 *
 * `match_slots.contested_title_ids` holds a Winner Takes All match's titles,
 * and `match_results.titles_won` what its winner took. Title shots gain a
 * kind (a #1 contendership has no due week) and a void reason.
 * `title_vacancy_prompts` asks the player to award or forfeit a vacated
 * title's #1 contendership; `gm_notices` are one-off notices for the GM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_slots', function (Blueprint $table) {
            $table->json('contested_title_ids')->nullable()->after('stipulation_championship_id');
        });

        Schema::table('match_results', function (Blueprint $table) {
            $table->json('titles_won')->nullable()->after('stipulation_outcome');
        });

        Schema::table('title_shots', function (Blueprint $table) {
            $table->string('kind', 30)->default('champion_vs_contender')->after('brand');
            $table->unsignedInteger('due_week')->nullable()->change();
            $table->string('void_reason', 40)->nullable()->after('status');
        });

        Schema::create('title_vacancy_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained();
            $table->foreignId('title_shot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['save_id', 'status']);
        });

        Schema::create('gm_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->json('payload');
            $table->unsignedInteger('week_number');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gm_notices');
        Schema::dropIfExists('title_vacancy_prompts');

        Schema::table('title_shots', function (Blueprint $table) {
            $table->dropColumn(['kind', 'void_reason']);
        });

        Schema::table('match_results', function (Blueprint $table) {
            $table->dropColumn('titles_won');
        });

        Schema::table('match_slots', function (Blueprint $table) {
            $table->dropColumn('contested_title_ids');
        });
    }
};
