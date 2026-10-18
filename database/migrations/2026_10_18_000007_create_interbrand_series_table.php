<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WrestleMania interbrand series and its re-draft prize (§23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interbrand_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedInteger('week_number');
            $table->json('match_ids');
            $table->unsignedTinyInteger('raw_wins')->default(0);
            $table->unsignedTinyInteger('smackdown_wins')->default(0);
            $table->string('winner_brand', 20)->nullable();
            $table->foreignId('exemption_wrestler_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->foreignId('handpick_wrestler_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->boolean('redraft_done')->default(false);
            $table->timestamps();
            $table->unique(['save_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interbrand_series');
    }
};
