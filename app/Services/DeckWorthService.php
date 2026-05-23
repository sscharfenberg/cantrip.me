<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\Locale;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the market-price worth of MTG decks in a user's currency.
 *
 * Single source of truth for the proxy-aware aggregation: each
 * deck_card's price contribution drops by the count of slots covered
 * by proxy stacks (clamped at the deck_card's quantity so a flag-only
 * / merge-anomaly stack can't push the contribution negative).
 * Real-card claims, multi-claim, and unclaimed slots all keep their
 * full printing price.
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

        $priceColumn = 'price_'.self::resolveCurrency($user)->value;

        $proxyClaims = DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->where('card_stacks.proxy', true)
            ->groupBy('deck_card_card_stack.deck_card_id')
            ->select('deck_card_card_stack.deck_card_id', DB::raw('SUM(card_stacks.amount) as proxy_amount'));

        return DB::table('deck_cards')
            ->join('default_cards', 'default_cards.id', '=', 'deck_cards.default_card_id')
            ->leftJoinSub($proxyClaims, 'proxy_claims', 'proxy_claims.deck_card_id', '=', 'deck_cards.id')
            ->whereIn('deck_cards.deck_id', $deckIds)
            ->groupBy('deck_cards.deck_id')
            ->selectRaw(
                'deck_cards.deck_id, COALESCE(SUM('
                .'(CASE WHEN deck_cards.quantity > COALESCE(proxy_claims.proxy_amount, 0) '
                .'THEN deck_cards.quantity - COALESCE(proxy_claims.proxy_amount, 0) ELSE 0 END) '
                ."* default_cards.{$priceColumn}"
                .'), 0) AS total'
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
