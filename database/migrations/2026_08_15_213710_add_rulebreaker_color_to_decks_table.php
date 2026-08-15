<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one colour a Rulebreaker commander lets the pilot nominate.
 *
 * Only Tolabow, Loch Rascal needs it today: "the color identity of instant and
 * sorcery cards in your deck can include one color of your choice not in your
 * commander's color identity". That choice is the pilot's, made once per deck,
 * and nothing in the card data can derive it — so it has to be stored.
 *
 * Deliberately NOT merged into `decks.colors`. That column is the deck's real
 * colour identity, and widening it would let ANY card of the chosen colour into
 * the deck; the nominated colour applies to instants and sorceries alone. The
 * two are separate axes and are kept as separate columns.
 *
 * NULL means "not chosen yet", which is a legal state: a Tolabow deck is
 * perfectly valid before the pilot picks, it simply gets no widening. It is
 * also what every non-Rulebreaker deck holds, so the column is nullable rather
 * than defaulted.
 *
 * A new migration rather than an edit to `create_decks_table` because prod
 * carries real user data and cannot be rebuilt from scratch.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->char('rulebreaker_color', 1)->nullable()->after('colors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn('rulebreaker_color');
        });
    }
};
