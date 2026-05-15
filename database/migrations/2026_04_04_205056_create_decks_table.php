<?php

use App\Enums\ContainerVisibility;
use App\Enums\DeckState;
use App\Models\Deck;
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
        Schema::create('decks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id')->primary();
            $table->string('name', Deck::NAME_MAX);
            $table->text('description')->nullable();
            $table->string('format', 32);
            $table->string('visibility', 16)
                ->default(ContainerVisibility::Private->value);
            $table->string('state', 16)
                ->default(DeckState::Planned->value);
            $table->string('colors', 5)->nullable();
            $table->unsignedTinyInteger('bracket')->nullable();
            // Per-deck collection-integration mode, explicitly set by the
            // user via the collection-mode modal. 'A' = off, 'B' = implicit
            // (container-based coverage), 'C' = explicit (per-card pivot
            // rows). New decks start in 'A'. Switching C → B/A cascades a
            // delete of every deck_card_card_stack pivot row attached to
            // this deck. The user-level `collection_integration_enabled`
            // master switch overrides this column at read time, presenting
            // every deck as effective mode A while the switch is off.
            // See {@see DeckCollectionStatusService::effectiveMode}.
            $table->string('collection_mode', 1)->default('A');
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('default_card_id')
                ->nullable()
                ->constrained('default_cards')
                ->cascadeOnDelete();
            $table->foreignUuid('container_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'format']);
            $table->index(['visibility']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decks');
    }
};
