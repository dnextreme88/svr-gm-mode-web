<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gauntlet (house rule): one match slot, five singles bouts. The slot's
 * match_results row holds the overall result; each bout is kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gauntlet_bouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->unsignedTinyInteger('side_a');
            $table->unsignedTinyInteger('side_b');
            $table->unsignedTinyInteger('winner_side');
            $table->string('method', 20);
            $table->string('finish_event', 30)->nullable();
            $table->foreignId('interferer_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->boolean('booked')->default(false);
            $table->text('summary');
            $table->timestamps();
            $table->unique(['match_slot_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gauntlet_bouts');
    }
};
