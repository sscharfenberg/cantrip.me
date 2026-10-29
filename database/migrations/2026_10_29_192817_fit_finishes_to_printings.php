<?php

use App\Enums\Finish;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fit every finish a printing does not exist in to one it does — on card
 * stacks and on deck cards alike.
 *
 * "Add all cards to collection" used to mint every stack nonfoil, whatever
 * the printing, so a Commander precon's foil-only card or a Secret Lair's
 * etched one went into the collection as a nonfoil that does not exist.
 * `add_copy_attributes_to_deck_cards_table` then copied those stacks'
 * values onto the deck cards they cover, impossible finish included.
 * Nothing at runtime writes such a finish any more: stacks are minted from
 * the deck card's plan, and `DeckCard` fits its finish to the printing on
 * every save.
 *
 * The finish taken is the printing's first — nonfoil, else foil, else
 * etched — the rule `Finish::fitTo` applies. A row whose finish the
 * printing has is left alone, as is a printing that lists no finish at
 * all, so running this where nothing is wrong changes nothing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['card_stacks', 'deck_cards'] as $table) {
            $this->fit($table);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: the finishes replaced did not exist.
     */
    public function down(): void {}

    /**
     * Rewrite the finishes of `$table` the row's printing does not have.
     */
    private function fit(string $table): void
    {
        $nonfoil = Finish::Nonfoil->value;
        $foil = Finish::Foil->value;
        $etched = Finish::Etched->value;

        $byFinish = [];
        DB::table($table)
            ->join('default_cards', 'default_cards.id', '=', "{$table}.default_card_id")
            ->whereRaw("(default_cards.finishes & {$table}.finish) = 0")
            ->where('default_cards.finishes', '!=', 0)
            ->get(["{$table}.id", 'default_cards.finishes'])
            ->each(function (object $row) use (&$byFinish, $nonfoil, $foil, $etched): void {
                $mask = (int) $row->finishes;
                $finish = match (true) {
                    ($mask & $nonfoil) !== 0 => $nonfoil,
                    ($mask & $foil) !== 0 => $foil,
                    default => $etched,
                };
                $byFinish[$finish][] = $row->id;
            });

        foreach ($byFinish as $finish => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update(['finish' => $finish]);
            }
        }
    }
};
