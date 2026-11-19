<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Save career checkpoints (house rule, 2026-11-17): one per career, the
     * whole career as it stood when the player last hit Save career. Loading
     * the career from Saved careers puts it back.
     */
    public function up(): void
    {
        Schema::create('save_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('save_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_week');
            $table->string('stage', 30);
            $table->bigInteger('budget')->nullable();
            // Row counts and latest timestamps per table, to tell whether
            // anything changed since the save.
            $table->string('fingerprint', 64);
            // gzip-compressed JSON, base64-encoded.
            $table->longText('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('save_checkpoints');
    }
};
