<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * House rule (2026-11-17): a show's match and promo money only reaches
     * the budget once the night's news is in. Until then its ledger lines
     * are recorded but not settled.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->boolean('settled')->default(true)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('settled');
        });
    }
};
