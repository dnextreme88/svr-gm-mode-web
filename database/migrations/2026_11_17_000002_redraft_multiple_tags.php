<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bigger re-draft (house rule, 2026-11-17): the immunity tag protects
     * several superstars and the manual draft pick takes several, so each
     * tag stores a list of save wrestler ids.
     */
    public function up(): void
    {
        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->json('exemption_wrestler_ids')->nullable()->after('immunity_brand');
            $table->json('handpick_wrestler_ids')->nullable()->after('exemption_wrestler_ids');
        });

        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exemption_wrestler_id');
            $table->dropConstrainedForeignId('handpick_wrestler_id');
        });
    }

    public function down(): void
    {
        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->foreignId('exemption_wrestler_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
            $table->foreignId('handpick_wrestler_id')->nullable()->constrained('save_wrestlers')->nullOnDelete();
        });

        Schema::table('interbrand_series', function (Blueprint $table) {
            $table->dropColumn(['exemption_wrestler_ids', 'handpick_wrestler_ids']);
        });
    }
};
