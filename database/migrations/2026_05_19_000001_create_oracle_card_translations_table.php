<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Oracle-level translations of card names — one row per
     * (oracle_card_id, lang). Populated by `scryfall:translations` from
     * Scryfall's `all_cards` bulk. Searched by the deck card-add /
     * collection-add / commander pickers so a German user can find
     * "Blitzschlag" (Lightning Bolt).
     *
     * Composite PK on (oracle_card_id, lang) — natural grain, no
     * surrogate id needed; dedupe across reprints happens in memory
     * during the streaming parse.
     *
     * Hot-path index on (lang, searchable_name) — search is
     * `WHERE lang = ? AND searchable_name LIKE '%term%'`, and the
     * leading equality on `lang` slices to ~30k rows before the
     * unanchored LIKE scans within the slice.
     */
    public function up(): void
    {
        Schema::create('oracle_card_translations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->foreignUuid('oracle_card_id')
                ->references('id')
                ->on('oracle_cards')
                ->cascadeOnDelete();
            $table->string('lang', 8);
            $table->string('printed_name', 255);
            $table->string('searchable_name', 255);

            $table->primary(['oracle_card_id', 'lang']);
            $table->index(['lang', 'searchable_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oracle_card_translations');
    }
};
