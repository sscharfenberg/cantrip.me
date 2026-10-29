<?php

use App\Enums\CardLanguage;
use App\Enums\Finish;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish, language and proxy for the copies of a deck card that are not in
 * the collection yet — the deck's *plan* ("I want this one foil, German").
 * A claimed copy is owned by its card stack instead, and the deck page reads
 * that copy's values from the stack; these columns are never read for it.
 *
 * `finish` and `language` existed on `deck_cards` once and were dropped in
 * 5b318b1 as dead columns. They return with that one job. `condition` stays
 * off: it describes a physical card, not a plan.
 *
 * A new migration rather than an edit to `create_deck_cards_table` because
 * prod carries real decks. The backfill gives every existing row the values
 * the app would have given it:
 *
 *  1. the printing's first available finish — nonfoil, else foil, else
 *     etched — so a foil-only printing (Atraxa from C16) isn't planned as a
 *     nonfoil that does not exist;
 *  2. for a row its claimed stacks cover in full, all of its printing and
 *     agreeing on finish, language and proxy, those stacks' values — the
 *     rule `DeckCardCopiesService::adoptClaimedValues` applies on every new
 *     claim, applied to the claims made before it existed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deck_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('finish')->default(Finish::Nonfoil->value)->after('quantity');
            $table->string('language', 3)->default(CardLanguage::En->value)->after('finish');
            $table->boolean('proxy')->default(false)->after('language');
        });

        $this->fitFinishToPrinting();
        $this->adoptClaimedValues();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deck_cards', function (Blueprint $table) {
            $table->dropColumn(['finish', 'language', 'proxy']);
        });
    }

    /**
     * Rows on a printing without a nonfoil version get its foil, or failing
     * that its etched, finish.
     */
    private function fitFinishToPrinting(): void
    {
        $nonfoil = Finish::Nonfoil->value;
        $foil = Finish::Foil->value;
        $etched = Finish::Etched->value;

        foreach ([$foil => "(finishes & {$foil}) <> 0", $etched => "(finishes & {$foil}) = 0 AND (finishes & {$etched}) <> 0"] as $finish => $condition) {
            DB::table('deck_cards')
                ->whereIn('default_card_id', fn ($query) => $query
                    ->select('id')
                    ->from('default_cards')
                    ->whereRaw("(finishes & {$nonfoil}) = 0")
                    ->whereRaw($condition))
                ->update(['finish' => $finish]);
        }
    }

    /**
     * Rows whose claimed stacks cover every copy, are all of the row's
     * printing and agree on all three values take those values.
     */
    private function adoptClaimedValues(): void
    {
        $claims = DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->groupBy('deck_card_card_stack.deck_card_id', 'deck_cards.quantity')
            ->selectRaw('deck_card_card_stack.deck_card_id AS deck_card_id')
            ->selectRaw('deck_cards.quantity AS quantity')
            ->selectRaw('SUM(card_stacks.amount) AS claimed')
            ->selectRaw('SUM(CASE WHEN card_stacks.default_card_id = deck_cards.default_card_id THEN 0 ELSE 1 END) AS other_printings')
            ->selectRaw('COUNT(DISTINCT card_stacks.finish) AS finishes')
            ->selectRaw('COUNT(DISTINCT card_stacks.language) AS languages')
            ->selectRaw('COUNT(DISTINCT card_stacks.proxy) AS proxies')
            ->selectRaw('MIN(card_stacks.finish) AS finish')
            ->selectRaw('MIN(card_stacks.language) AS language')
            ->selectRaw('MIN(card_stacks.proxy) AS proxy')
            ->get();

        foreach ($claims as $claim) {
            if ((int) $claim->claimed < (int) $claim->quantity
                || (int) $claim->other_printings !== 0
                || (int) $claim->finishes !== 1
                || (int) $claim->languages !== 1
                || (int) $claim->proxies !== 1) {
                continue;
            }

            DB::table('deck_cards')->where('id', $claim->deck_card_id)->update([
                'finish' => (int) $claim->finish,
                'language' => $claim->language,
                'proxy' => (bool) $claim->proxy,
            ]);
        }
    }
};
