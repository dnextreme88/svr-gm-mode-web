<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trades between brands (§8, §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->string('proposer_brand', 20);
            $table->string('status', 20);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('save_wrestler_id')->constrained()->cascadeOnDelete();
            // Out = leaves the proposer; in = joins the proposer.
            $table->string('direction', 5);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_items');
        Schema::dropIfExists('trades');
    }
};
