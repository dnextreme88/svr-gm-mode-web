<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live results: the player decides their own matches one at a time on the
 * results screen (Simulate or Book to Win). A week that is airing keeps its
 * resolution state here between requests. Match results remember whether
 * the winner was booked and which reign was on the line going in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('week_airings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->json('context');
            $table->json('rng_states');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['save_id', 'week_number']);
        });

        Schema::table('match_results', function (Blueprint $table) {
            $table->boolean('booked')->default(false)->after('interferer_id');
            $table->foreignId('champion_reign_id')->nullable()->after('title_changed')->constrained('title_reigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('champion_reign_id');
            $table->dropColumn('booked');
        });

        Schema::dropIfExists('week_airings');
    }
};
