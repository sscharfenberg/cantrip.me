<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\DeckZone;
use App\Enums\Locale;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the market-price worth of MTG decks in a user's currency.
 *
 * Priced per copy, by whoever owns the copy's finish and proxy flag (see
 * {@see DeckCardCopiesService}): a claimed copy at its card stack's price,
 * an unclaimed one at the deck card's planned finish — nonfoil, foil or
 * etched price, a proxy at nothing. Both go through
 * {@see ContainerService::unitPriceSql}, the rule the collection prices its
 * stacks by, so a foil is worth the same in a deck as in a binder. A finish
 * without a price counts 0, as it does in the collection.
 *
 * A row whose claimed stacks hold more copies than the row (a multi-claim
 * anomaly) counts their average price for its quantity, never more copies
 * than it has.
 *
 * The maybeboard is left out: it's a scratch pile, not part of the
 * deck — the same reason the deck list's card count skips it.
 *
 * The `?User` parameter accepts null so guests browsing a public deck
 * resolve to the locale's default currency.
 */
class DeckWorthService
{
    /**
     * Per-deck market worth, keyed by deck_id. Returned values are raw
     * floats — round at the call site if display rounding is desired.
     *
     * Returns an empty Collection when `$deckIds` is empty (skips the
     * roundtrip to the database entirely).
     *
     * @param  array<int, string>  $deckIds
     * @return Collection<string, float>
     */
    public static function perDeckTotals(?User $user, array $deckIds): Collection
    {
        if ($deckIds === []) {
            return collect();
        }

        $currency = self::resolveCurrency($user);
        $claimedPrice = 'COALESCE('.ContainerService::unitPriceSql($currency).', 0)';
        $plannedPrice = 'COALESCE('.ContainerService::unitPriceSql($currency, 'deck_cards').', 0)';

        $claims = DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->join('default_cards', 'default_cards.id', '=', 'card_stacks.default_card_id')
            ->whereIn('deck_card_card_stack.deck_card_id', fn ($query) => $query
                ->select('id')
                ->from('deck_cards')
                ->whereIn('deck_id', $deckIds))
            ->groupBy('deck_card_card_stack.deck_card_id')
            ->select('deck_card_card_stack.deck_card_id')
            ->selectRaw('SUM(card_stacks.amount) AS claimed_amount')
            ->selectRaw("SUM(card_stacks.amount * {$claimedPrice}) AS claimed_worth");

        return DB::table('deck_cards')
            ->join('default_cards', 'default_cards.id', '=', 'deck_cards.default_card_id')
            ->leftJoinSub($claims, 'claims', 'claims.deck_card_id', '=', 'deck_cards.id')
            ->whereIn('deck_cards.deck_id', $deckIds)
            ->where('deck_cards.zone', '!=', DeckZone::Maybe->value)
            ->groupBy('deck_cards.deck_id')
            ->selectRaw(
                'deck_cards.deck_id, COALESCE(SUM(CASE '
                .'WHEN COALESCE(claims.claimed_amount, 0) > deck_cards.quantity '
                .'THEN 1.0 * claims.claimed_worth * deck_cards.quantity / claims.claimed_amount '
                .'ELSE COALESCE(claims.claimed_worth, 0) '
                ."+ (deck_cards.quantity - COALESCE(claims.claimed_amount, 0)) * {$plannedPrice} "
                .'END), 0) AS total'
            )
            ->pluck('total', 'deck_id')
            ->map(fn ($total): float => (float) $total);
    }

    /**
     * One deck's market worth, rounded to two decimals for display.
     */
    public static function totalForDeck(?User $user, Deck $deck): float
    {
        return round((float) (self::perDeckTotals($user, [$deck->id])->first() ?? 0.0), 2);
    }

    /**
     * Resolve the currency to bill against. Users have an explicit
     * `currency` cast; guests (and users with the column unset) fall
     * back to whatever the active locale considers its default.
     */
    private static function resolveCurrency(?User $user): Currency
    {
        return $user?->currency ?? Locale::from(app()->getLocale())->defaultCurrency();
    }
}
