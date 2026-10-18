<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries (§6, §11). An injury is active while week < ends_week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('injuries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->foreignId('injury_type_id')->constrained();
            $table->string('severity', 20);
            $table->unsignedInteger('started_week');
            $table->unsignedInteger('ends_week');
            $table->boolean('escalated_from_minor')->default(false);
            $table->foreignId('escalated_from_id')->nullable()->constrained('injuries')->nullOnDelete();
            $table->unsignedInteger('healed_week')->nullable();
            $table->unsignedBigInteger('match_slot_id')->nullable();
            $table->timestamps();
            $table->index(['save_wrestler_id', 'ends_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('injuries');
    }
};
