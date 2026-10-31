<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fan attendance (house rule, 2026-11-01): each show, or each PPV night, is
 * scored once it airs and turned into a crowd. A PPV night's cards share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->unsignedInteger('attendance')->nullable();
            // The draw score's factors, the noise, the kind of show and the gate.
            $table->json('attendance_breakdown')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['attendance', 'attendance_breakdown']);
        });
    }
};
