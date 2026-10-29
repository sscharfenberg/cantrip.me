<?php

namespace App\Services;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\DeckCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finish, language and proxy of a deck card's copies — "one owner at a time".
 *
 * Each copy of a deck card has exactly one owner of those values, and nothing
 * is ever synced between the two:
 *
 *  - a copy **claimed** by a card stack is a fact about a physical card. The
 *    stack owns the values; the deck shows them live from the stack.
 *  - a copy **not claimed** is a plan. The deck card's own `finish`,
 *    `language` and `proxy` columns own them.
 *
 * Per copy, not per row: "Assign physical copy" can claim 2 of a 4-copy row,
 * and +1 on a fully claimed row adds an unclaimed copy, so one row can show
 * both. The deck card's columns are never read for a claimed copy — which is
 * also why they stay filled while claimed: they are the plan for any
 * unclaimed copies, and what the row falls back to when a claim ends
 * (unclaimed, stack deleted, mode switched away from C).
 *
 * Data only moves at the two hand-offs, never afterwards:
 *
 *  - a stack **minted** for a deck card takes the deck card's values
 *    ("add all to collection", "bought new") — the callers read
 *    {@see DeckCard::planAttributes};
 *  - a deck card whose new claims cover every copy takes the claimed values
 *    ({@see adoptClaimedValues}), so it remembers what was in the box.
 *
 * None of the three values affects coverage, availability or the collection
 * status badges: a nonfoil copy covers a foil plan.
 *
 * The deck page edits each copy where its values live: the plan through
 * {@see replan}, a claimed copy through {@see editClaimedStack}, which
 * writes the collection.
 */
class DeckCardCopiesService
{
    /**
     * The copies of each card, by deck card id, as the deck page's markers
     * render them: one group per claimed stack, then one group for the copies
     * no stack claims (absent when there are none).
     *
     * Without `$withClaims` — a visitor, or the owner while the collection
     * master switch is off — every copy is shown as planned: collection state
     * stays private, as everywhere else on the deck page.
     *
     * @param  Collection<int, DeckCard>  $deckCards
     * @return array<string, list<array{count: int, finish: string, language: string, proxy: bool, claimed: bool}>>
     */
    public static function groupsFor(Collection $deckCards, bool $withClaims): array
    {
        $claims = $withClaims ? self::claimedStacks($deckCards->pluck('id')->all()) : [];

        $groups = [];
        foreach ($deckCards as $deckCard) {
            $rows = [];
            $claimed = 0;
            foreach ($claims[$deckCard->id] ?? [] as $stack) {
                $rows[] = [
                    'count' => $stack['amount'],
                    'finish' => $stack['finish']->label(),
                    'language' => $stack['language']->value,
                    'proxy' => $stack['proxy'],
                    'claimed' => true,
                ];
                $claimed += $stack['amount'];
            }
            $open = max($deckCard->quantity - $claimed, 0);
            if ($open > 0) {
                $rows[] = [
                    'count' => $open,
                    'finish' => $deckCard->finish->label(),
                    'language' => $deckCard->language->value,
                    'proxy' => $deckCard->proxy,
                    'claimed' => false,
                ];
            }
            $groups[$deckCard->id] = $rows;
        }

        return $groups;
    }

    /**
     * How many copies of the card no stack claims — what the plan covers.
     */
    public static function unclaimedCount(DeckCard $deckCard): int
    {
        $claimed = (int) $deckCard->cardStacks()->sum('card_stacks.amount');

        return max($deckCard->quantity - $claimed, 0);
    }

    /**
     * After new claims: when they cover every copy, are all of the card's
     * printing and agree on finish, language and proxy, the deck card takes
     * those values. A claim that covers only part of the row leaves the plan
     * alone — the unclaimed copies are still planned as they were.
     *
     * The migration that added the columns applied the same rule to the
     * claims made before them.
     */
    public static function adoptClaimedValues(DeckCard $deckCard): void
    {
        /** @var Collection<int, CardStack> $stacks */
        $stacks = $deckCard->cardStacks()->get();
        if ($stacks->isEmpty() || (int) $stacks->sum('amount') < $deckCard->quantity) {
            return;
        }
        if ($stacks->contains(fn (CardStack $stack): bool => $stack->default_card_id !== $deckCard->default_card_id)) {
            return;
        }
        $first = $stacks->first();
        $agree = $stacks->every(fn (CardStack $stack): bool => $stack->finish === $first->finish
            && $stack->language === $first->language
            && $stack->proxy === $first->proxy);
        if (! $agree) {
            return;
        }

        $deckCard->update([
            'finish' => $first->finish,
            'language' => $first->language,
            'proxy' => $first->proxy,
        ]);
    }

    /**
     * Re-plan `$count` of the card's unclaimed copies.
     *
     * All of them: the deck card's own values change — on a partly claimed
     * row that re-plans only the unclaimed copies, since the claimed ones show
     * their stacks. Fewer: they split off into a new row of the same card,
     * printing, zone and category carrying the new values. Either way a row
     * left unclaimed and identical to an unclaimed sibling folds into it, so
     * marking two copies foil and back again leaves one row, not two.
     *
     * The caller validates `$count` (1 … {@see unclaimedCount}) and that the
     * finish exists for the printing. The row is re-read under a lock, so two
     * re-plans racing (a second tab, a retried request) cannot both subtract
     * from the quantity they read; a count the row no longer has is cut to
     * what is open now. Values equal to the plan change nothing — splitting
     * off an identical row would only leave a twin that cannot fold back when
     * the row is claimed.
     *
     * @return DeckCard The row now holding the re-planned copies.
     */
    public static function replan(DeckCard $deckCard, int $count, Finish $finish, CardLanguage $language, bool $proxy): DeckCard
    {
        return DB::transaction(function () use ($deckCard, $count, $finish, $language, $proxy): DeckCard {
            $deckCard = DeckCard::query()->lockForUpdate()->findOrFail($deckCard->id);
            if ($deckCard->finish === $finish && $deckCard->language === $language && $deckCard->proxy === $proxy) {
                return $deckCard;
            }
            $open = self::unclaimedCount($deckCard);
            if ($open === 0) {
                return $deckCard;
            }
            $values = ['finish' => $finish, 'language' => $language, 'proxy' => $proxy];

            if ($count >= $open) {
                $deckCard->update($values);
                $target = $deckCard;
            } else {
                $deckCard->update(['quantity' => $deckCard->quantity - $count]);
                $target = DeckCard::create([
                    'deck_id' => $deckCard->deck_id,
                    'oracle_card_id' => $deckCard->oracle_card_id,
                    'default_card_id' => $deckCard->default_card_id,
                    'category_id' => $deckCard->category_id,
                    'zone' => $deckCard->zone->value,
                    'quantity' => $count,
                    ...$values,
                ]);
            }

            return self::foldIntoTwin($target);
        });
    }

    /**
     * Edit `$count` copies of a stack claimed for a deck card — "Edit copies"
     * on a claimed copy, the deck page's way into the collection. The stack
     * owns these values, so this writes the stack and leaves the deck card's
     * plan alone.
     *
     * All of its copies: the stack itself changes. Fewer: they are split off
     * first ({@see CardStackService::splitStack}) and only the split-off part
     * changes. The split-off part keeps every claim the stack had — on this
     * card and on any other deck's — so it is still the same physical cards
     * in the same decks, only now told apart.
     *
     * The stack is re-read under a lock, as {@see replan} re-reads its row;
     * a count it no longer has is cut to its amount, and values it already
     * has change nothing. The edited cards fold into a sibling stack that is
     * now the same cards in the same decks, as {@see replan} folds rows. The
     * caller validates the rest: the stack is claimed for the card, the
     * finish exists for its printing.
     *
     * @return CardStack The stack now holding the edited copies.
     */
    public static function editClaimedStack(
        CardStack $stack,
        int $count,
        Finish $finish,
        CardLanguage $language,
        bool $proxy,
        ?CardCondition $condition,
    ): CardStack {
        return DB::transaction(function () use ($stack, $count, $finish, $language, $proxy, $condition): CardStack {
            $stack = CardStack::query()->lockForUpdate()->findOrFail($stack->id);
            if ($stack->finish === $finish
                && $stack->language === $language
                && $stack->proxy === $proxy
                && $stack->condition === $condition) {
                return $stack;
            }

            $target = $stack;
            if ($count < $stack->amount) {
                $target = CardStackService::splitStack($stack, $count);
                $claims = DB::table('deck_card_card_stack')
                    ->where('card_stack_id', $stack->id)
                    ->pluck('deck_card_id')
                    ->all();
                $target->deckCards()->attach($claims);
            }

            $target->update([
                'finish' => $finish,
                'language' => $language,
                'proxy' => $proxy,
                'condition' => $condition,
            ]);

            return self::foldIntoTwinStack($target);
        });
    }

    /**
     * Fold an edited stack into a sibling that is now the same cards: same
     * owner, printing, container, finish, language, condition and proxy flag,
     * claimed by exactly the same deck cards. Without it, marking one of two
     * copies foil and back again would leave two identical stacks in the
     * binder and two identical groups on the deck.
     */
    private static function foldIntoTwinStack(CardStack $stack): CardStack
    {
        $claims = self::claimSet($stack->id);
        $twins = CardStack::query()
            ->where('id', '!=', $stack->id)
            ->where('user_id', $stack->user_id)
            ->where('default_card_id', $stack->default_card_id)
            ->when(
                $stack->container_id === null,
                fn ($query) => $query->whereNull('container_id'),
                fn ($query) => $query->where('container_id', $stack->container_id),
            )
            ->when(
                $stack->condition === null,
                fn ($query) => $query->whereNull('condition'),
                fn ($query) => $query->where('condition', $stack->condition->value),
            )
            ->where('finish', $stack->finish->value)
            ->where('language', $stack->language->value)
            ->where('proxy', $stack->proxy)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($twins as $twin) {
            if (self::claimSet($twin->id) === $claims) {
                $twin->update(['amount' => $twin->amount + $stack->amount]);
                $stack->delete();

                return $twin;
            }
        }

        return $stack;
    }

    /**
     * The deck cards claiming a stack, sorted, for comparing two stacks.
     *
     * @return list<string>
     */
    private static function claimSet(string $stackId): array
    {
        return DB::table('deck_card_card_stack')
            ->where('card_stack_id', $stackId)
            ->orderBy('deck_card_id')
            ->pluck('deck_card_id')
            ->all();
    }

    /**
     * Fold an unclaimed row into an unclaimed sibling that is the same card,
     * printing, zone and category with the same plan. Rows with a role (the
     * command zone, the companion) are single slots and never fold; claimed
     * rows never fold either, so no claim ever moves.
     */
    private static function foldIntoTwin(DeckCard $row): DeckCard
    {
        if ($row->role !== null || $row->cardStacks()->exists()) {
            return $row;
        }

        $twin = DeckCard::query()
            ->where('deck_id', $row->deck_id)
            ->where('id', '!=', $row->id)
            ->where('oracle_card_id', $row->oracle_card_id)
            ->where('default_card_id', $row->default_card_id)
            ->where('zone', $row->zone->value)
            ->whereNull('role')
            ->when(
                $row->category_id === null,
                fn ($query) => $query->whereNull('category_id'),
                fn ($query) => $query->where('category_id', $row->category_id),
            )
            ->where('finish', $row->finish->value)
            ->where('language', $row->language->value)
            ->where('proxy', $row->proxy)
            ->whereDoesntHave('cardStacks')
            ->orderBy('created_at')
            ->first();
        if ($twin === null) {
            return $row;
        }

        $twin->update(['quantity' => $twin->quantity + $row->quantity]);
        $row->delete();

        return $twin;
    }

    /**
     * The stacks claimed for each of the given deck cards, oldest claim first.
     *
     * @param  array<int, string>  $deckCardIds
     * @return array<string, list<array{amount: int, finish: Finish, language: CardLanguage, proxy: bool}>>
     */
    private static function claimedStacks(array $deckCardIds): array
    {
        if ($deckCardIds === []) {
            return [];
        }

        $result = [];
        DB::table('deck_card_card_stack')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->whereIn('deck_card_card_stack.deck_card_id', $deckCardIds)
            ->orderBy('card_stacks.created_at')
            ->orderBy('card_stacks.id')
            ->get([
                'deck_card_card_stack.deck_card_id',
                'card_stacks.amount',
                'card_stacks.finish',
                'card_stacks.language',
                'card_stacks.proxy',
            ])
            ->each(function (object $row) use (&$result): void {
                $result[$row->deck_card_id][] = [
                    'amount' => (int) $row->amount,
                    'finish' => Finish::from((int) $row->finish),
                    'language' => CardLanguage::from($row->language),
                    'proxy' => (bool) $row->proxy,
                ];
            });

        return $result;
    }
}
