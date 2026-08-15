<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every face's type line, joined with " // " — the same string Scryfall puts
 * at the root of a multi-faced card. Denormalised from `oracle_card_faces` on
 * purpose.
 *
 * Rules that exempt cards by TYPE rather than by colour — the Rulebreaker
 * commanders, which let a deck run e.g. Angel or Aura cards outside its colour
 * identity — have to be expressible in the deck-card search filter, and that
 * filter is one predicate against `oracle_cards`. Reaching the faces from
 * there means an EXISTS subquery, which is fine while a name prefix is
 * narrowing the scan but not otherwise: on a token-only query (`set:fdn` with
 * no name segment) it measured 44ms against 3ms for this column, over 38k
 * oracle rows on staging. Widest real value today is 91 chars across 5 faces,
 * so 160 is headroom rather than a guess.
 *
 * A new migration rather than an edit to `create_oracle_cards_table` because
 * prod carries real user data and cannot be rebuilt from scratch — the column
 * has to arrive by deploy, so `scryfall:update` can populate it on its next
 * run. Note the ordering constraint that creates: the import writes this
 * column, and `ShadowTableService` builds its shadow tables with
 * `CREATE TABLE LIKE` off the live schema, so this migration must land BEFORE
 * the next sync. Until then the column is simply NULL for every row, which no
 * caller treats as an error.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oracle_cards', function (Blueprint $table) {
            $table->string('type_line', 160)->nullable()->after('color_identity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oracle_cards', function (Blueprint $table) {
            $table->dropColumn('type_line');
        });
    }
};
