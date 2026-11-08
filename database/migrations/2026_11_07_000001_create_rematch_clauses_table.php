<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Rematch Clause (house rule, 2026-11-07): when a title changes hands in a
| match, the former champion(s) are owed a title match against the new
| champion(s) within 4 weeks. An Active Effect only: it never blocks a card.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rematch_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained();
            $table->string('brand', 20);
            $table->foreignId('title_reign_id')->constrained()->cascadeOnDelete();
            $table->json('former_ids');
            $table->json('champion_ids');
            $table->unsignedInteger('created_week');
            $table->unsignedInteger('expires_week');
            $table->string('status', 20);
            $table->foreignId('resolved_match_id')->nullable()->constrained('match_slots')->nullOnDelete();
            $table->unsignedInteger('resolved_week')->nullable();
            $table->timestamps();
            $table->index(['save_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rematch_clauses');
    }
};
