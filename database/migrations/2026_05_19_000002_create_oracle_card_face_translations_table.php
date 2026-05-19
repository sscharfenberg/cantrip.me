<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Face-level translations of card names for multi-faced layouts
     * (transform, MDFC, split, adventure, etc.) — one row per
     * (oracle_card_id, face_index, lang). Populated by
     * `scryfall:translations` from Scryfall's `all_cards` bulk.
     *
     * The FK cascades through `oracle_card_id` only — no composite FK
     * to (oracle_card_id, face_index). The data only ever turns over
     * via full truncate-rebuild, so an oracle delete cascading to
     * orphan face rows is a non-issue; both this table and the
     * sibling `oracle_card_faces` rows die in the same cascade.
     *
     * Hot-path index on (lang, searchable_name) — same rationale as
     * the oracle-level table; the face row for "Smelting Vat // Furnace
     * Reins" (transform) gets matched independently of the oracle row.
     */
    public function up(): void
    {
        Schema::create('oracle_card_face_translations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->foreignUuid('oracle_card_id')
                ->references('id')
                ->on('oracle_cards')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('face_index');
            $table->string('lang', 8);
            $table->string('printed_name', 255);
            $table->string('searchable_name', 255);

            $table->primary(['oracle_card_id', 'face_index', 'lang']);
            $table->index(['lang', 'searchable_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oracle_card_face_translations');
    }
};
