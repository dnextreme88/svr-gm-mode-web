<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House rule (2026-11-15): the re-draft's tags are split. The immunity tag
 * goes to the brand that drew more fans before WrestleMania; the manual
 * draft pick stays with the interbrand series winner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->string('immunity_brand', 20)->nullable()->after('winner_brand');
        });
    }

    public function down(): void
    {
        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->dropColumn('immunity_brand');
        });
    }
};
