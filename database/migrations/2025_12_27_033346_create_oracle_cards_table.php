<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('oracle_cards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('searchable_name', 160);
            $table->string('collector_number', 10);
            $table->string('layout', 48);
            $table->string('lang', 8);
            $table->decimal('cmc', 8, 1);
            $table->string('color_identity', 5)->nullable();
            $table->string('produced_mana', 6)->nullable();
            $table->boolean('reserved')->default(false);
            $table->boolean('game_changer')->default(false);
            // "Mass land denial" — populated from Scryfall's `otag:mass-land-denial`
            // tagger search (not a top-level Card field, only reachable via the
            // search endpoint). Synced by `scryfall:oracle-tags`. Used by the
            // bracket auto-suggest hint on the deck-edit page.
            $table->boolean('mld')->default(false);
            $table->string('scryfall_uri', 255);

            $table->index('searchable_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oracle_cards');
    }
};
