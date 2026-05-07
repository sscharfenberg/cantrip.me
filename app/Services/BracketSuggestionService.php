<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Compute a minimum-bracket suggestion for a deck based on the
 * deterministic axes of the Commander Bracket spec we can actually read
 * from card data: presence of mass-land-denial (MLD) cards, and the
 * count of game changers.
 *
 * The "speed" / "tutoring" / "early-game two-card combo" axes from the
 * spec are intentionally excluded — they require playtesting data or
 * combo-curation we explicitly chose not to maintain. The suggestion is
 * therefore a *floor*, not a definitive bracket: a deck that scores 3
 * here may still belong in 4 due to fuzzy factors. Surfaced as a hint
 * under the bracket dropdown, never as a forced value.
 *
 * Reason mapping:
 *   - any MLD card present                      → minimum 4 (reason: mld)
 *   - 4 or more distinct game changers          → minimum 4 (reason: game_changers)
 *   - 1 to 3 distinct game changers             → minimum 3 (reason: game_changers)
 *   - none of the above                         → no suggestion (null)
 */
class BracketSuggestionService
{
    /**
     * @return array{minimum: int, reason: string, game_changers: int, mld: int}|null
     */
    public static function suggest(Deck $deck): ?array
    {
        // Post-consolidation, every card in the deck (mainboard, sideboard,
        // command zone, companion) lives in `deck_cards`, so a single
        // pluck covers what used to span three sources.
        $oracleIds = $deck->deckCards
            ->pluck('oracle_card_id')
            ->filter()
            ->unique()
            ->values();

        if ($oracleIds->isEmpty()) {
            return null;
        }

        $cards = OracleCard::query()
            ->whereIn('id', $oracleIds)
            ->get(['id', 'game_changer', 'mld']);

        $gameChangers = $cards->where('game_changer', true)->count();
        $mldCount = $cards->where('mld', true)->count();

        if ($mldCount > 0) {
            return [
                'minimum' => 4,
                'reason' => 'mld',
                'game_changers' => $gameChangers,
                'mld' => $mldCount,
            ];
        }

        if ($gameChangers >= 4) {
            return [
                'minimum' => 4,
                'reason' => 'game_changers',
                'game_changers' => $gameChangers,
                'mld' => 0,
            ];
        }

        if ($gameChangers >= 1) {
            return [
                'minimum' => 3,
                'reason' => 'game_changers',
                'game_changers' => $gameChangers,
                'mld' => 0,
            ];
        }

        return null;
    }
}
