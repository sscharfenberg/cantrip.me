<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres creates no index for a foreign key, so these columns were read
 * whole on every lookup through them: all printings of a set of oracle
 * cards (13 ms of a 40 ms deck page), the stacks in a container, the cards
 * of a deck category. `oracle_cards.name` is no foreign key, but the
 * companion roster and partner lookups match it exactly (another 7 ms).
 * `ForeignKeyIndexTest` keeps the foreign keys covered from here on.
 *
 * The Scryfall tables' shadow copies are created LIKE their live tables,
 * indexes included, so the nightly sync carries these indexes through
 * the swap. Do not run this while `scryfall:update` builds (04:00 Berlin
 * time): the swap would refuse a live table whose indexes changed.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>> table → columns, one index each
     */
    private const INDEXES = [
        'default_cards' => ['oracle_id', 'artist_id'],
        'default_card_relations' => ['related_default_card_id'],
        'oracle_cards' => ['name'],
        'card_stacks' => ['container_id'],
        'containers' => ['default_card_id'],
        'decks' => ['container_id', 'default_card_id'],
        'deck_categories' => ['deck_id'],
        'deck_cards' => ['category_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    }
};
