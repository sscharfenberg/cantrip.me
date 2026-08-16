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
 * the next sync.
 *
 * BETWEEN DEPLOY AND THAT SYNC THE COLUMN IS NULL FOR EVERY ROW, and callers
 * added later do read it: the Rulebreaker type-based exemptions match on it, so
 * a Tolabow deck's instant/sorcery widening is inert until the sync runs — the
 * picker still shows the nominated colour while the cards it should legalise go
 * on reporting violations. Nothing errors, and the basic-land exemption is
 * unaffected, but the gap is real. Run `php artisan scryfall:update` promptly
 * after deploying this, rather than waiting for the nightly cron.
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
