<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Title shots and Last Chance lockouts (§22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_slots', function (Blueprint $table) {
            // The title a Champion vs. Contender match is about (not on the line).
            $table->foreignId('stipulation_championship_id')->nullable()->after('stipulation')->constrained('championships');
        });

        Schema::create('title_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained();
            $table->string('brand', 20);
            $table->json('contender_ids');
            $table->json('champion_ids');
            $table->foreignId('earned_match_id')->constrained('match_slots')->cascadeOnDelete();
            $table->unsignedInteger('earned_week');
            $table->unsignedInteger('due_week');
            $table->foreignId('fulfilled_match_id')->nullable()->constrained('match_slots')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('resolved_week')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'status']);
        });

        Schema::create('last_chance_lockouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained();
            $table->foreignId('title_reign_id')->constrained()->cascadeOnDelete();
            $table->json('wrestler_ids');
            $table->foreignId('match_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('created_week');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('last_chance_lockouts');
        Schema::dropIfExists('title_shots');

        Schema::table('match_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stipulation_championship_id');
        });
    }
};
