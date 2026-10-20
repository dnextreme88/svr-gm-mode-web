<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5 promo rework: championship targets, Diva pairs, promo fatigue, the
 * player-facing requirement text, and where on the card each promo airs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_types', function (Blueprint $table) {
            $table->boolean('needs_championship')->default(false)->after('needs_rival_pair');
            $table->boolean('needs_diva_pair')->default(false)->after('needs_championship');
            $table->unsignedTinyInteger('fatigue_bars')->default(0)->after('needs_diva_pair');
            $table->string('requirement')->nullable()->after('effect');
        });

        Schema::table('promo_slots', function (Blueprint $table) {
            $table->unsignedTinyInteger('before_position')->nullable()->after('position');
            $table->foreignId('target_championship_id')->nullable()->after('target_wrestler_b_id')->constrained('championships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promo_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_championship_id');
            $table->dropColumn('before_position');
        });

        Schema::table('promo_types', function (Blueprint $table) {
            $table->dropColumn(['needs_championship', 'needs_diva_pair', 'fatigue_bars', 'requirement']);
        });
    }
};
