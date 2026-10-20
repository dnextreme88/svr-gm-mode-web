<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each wrestler's fatigue through one week: going in, after the shows, and
 * after weekly recovery. The results screen animates these steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fatigue_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('start');
            $table->unsignedTinyInteger('after_shows');
            $table->unsignedTinyInteger('end');
            $table->unsignedTinyInteger('recovered_bars');
            $table->timestamps();
            $table->unique(['save_id', 'week_number', 'save_wrestler_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatigue_logs');
    }
};
