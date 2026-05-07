<?php

use App\Enums\DeckZone;
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
        Schema::create('deck_cards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id')->primary();
            $table->foreignUuid('deck_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('oracle_card_id')
                ->constrained('oracle_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('default_card_id')
                ->constrained('default_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('category_id')
                ->nullable()
                ->constrained('deck_categories')
                ->nullOnDelete();
            $table->string('zone', 16)
                ->default(DeckZone::Main->value);
            // Optional role tag, orthogonal to `zone`. NULL for normal
            // mainboard / sideboard / maybeboard rows. Set to one of
            // `commander | partner | signature_spell | companion` for
            // the special slots that used to live in the now-defunct
            // `commanders` table and `decks.companion_*` columns.
            $table->string('role', 32)->nullable();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->timestamps();

            $table->index(['deck_id', 'zone']);
            $table->index(['oracle_card_id']);
            $table->index(['default_card_id']);
            // `(deck_id, role)` is unique at the schema level so a deck
            // can never have two commanders / two partners / two
            // signature spells / two companions. MySQL UNIQUE allows
            // multiple NULLs, so mainboard rows are unaffected.
            $table->unique(['deck_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deck_cards');
    }
};
