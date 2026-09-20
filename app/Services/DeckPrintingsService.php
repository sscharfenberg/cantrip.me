<?php

namespace App\Services;

use App\Enums\ContainerType;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\DefaultCard;
use App\Models\User;

/**
 * Shared printings-listing logic for the switch-printing modal.
 *
 * Three switch-printing flows (deck card, companion, commander) all surface
 * the same picker UI with the same shape: every printing of a given oracle
 * card, ordered by set release date (newest first), each marked with whether
 * the user owns a copy outside their deckboxes (`in_collection`) and whether
 * it's the printing currently selected by the caller (`is_current`).
 *
 * Centralising the query here keeps the three controllers' `printings()`
 * methods to a single line, and makes the response shape unambiguous so the
 * frontend can keep reusing `DeckCardSwitchPrintingModal` for every flow.
 */
final class DeckPrintingsService
{
    /**
     * List every printing of the given oracle card.
     *
     * @param  string|int  $userId  Authenticated user — drives the
     *                              `in_collection` flag (cards in deckboxes
     *                              are excluded since they're earmarked for
     *                              decks rather than freely available).
     * @param  string  $oracleCardId  Oracle card whose printings to list.
     * @param  string|null  $currentDefaultCardId  Currently-selected
     *                                             printing's id, used to flag `is_current`
     *                                             on the matching row. Null when nothing
     *                                             is selected yet.
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     card_image_0: string|null,
     *     card_image_1: string|null,
     *     artist: string|null,
     *     cn: string,
     *     finishes: array<string>,
     *     set: array{name: string, code: string, path: string|null}|null,
     *     in_collection: bool,
     *     is_current: bool,
     *     owned: int|null,
     * }>
     */
    public static function listForOracle(
        User $user,
        string $oracleCardId,
        ?string $currentDefaultCardId,
    ): array {
        // Single query: join sets (needed for ORDER BY released_at + payload
        // columns) and artists. We *don't* `with(['set'])` on top of the join
        // because that would issue a second query for the same set rows we
        // already have in scope; the joined `set_*` columns are mapped
        // directly into the printing payload below.
        $printings = DefaultCard::query()
            ->with(['artist:id,name'])
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('default_cards.oracle_id', $oracleCardId)
            ->orderBy('sets.released_at', 'desc')
            ->orderBy('default_cards.id', 'desc')
            ->select(
                'default_cards.*',
                'sets.name as set_name',
                'sets.code as set_code',
                'sets.path as set_path'
            )
            ->get();

        $availableIds = CardStack::query()
            ->leftJoin('containers', 'card_stacks.container_id', '=', 'containers.id')
            ->where('card_stacks.user_id', $user->id)
            ->whereIn('card_stacks.default_card_id', $printings->pluck('id')->all())
            ->where(function ($query): void {
                $query->whereNull('card_stacks.container_id')
                    ->orWhere('containers.type', '!=', ContainerType::Deckbox->value);
            })
            ->pluck('card_stacks.default_card_id')
            ->unique()
            ->flip();

        // `in_collection` answers "is one of these free to take?" — it
        // deliberately skips deckboxes. `owned` is the plain shelf count of
        // every copy wherever it sits, which is what the face-image panel
        // badge shows, so the two disagree for a printing that only exists
        // inside a deckbox. Both are wanted.
        $ownedAmounts = CardStackService::ownedAmountsFor($user, $printings->pluck('id')->all());

        return $printings
            ->map(fn (DefaultCard $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'card_image_0' => $card->card_image_0,
                'card_image_1' => $card->card_image_1,
                'artist' => $card->artist?->name,
                'cn' => $card->collector_number,
                'finishes' => Finish::labelsFromMask($card->finishes),
                'set' => $card->set_code !== null ? [
                    'name' => $card->set_name,
                    'code' => $card->set_code,
                    'path' => $card->set_path,
                ] : null,
                'in_collection' => $availableIds->has($card->id),
                'is_current' => $card->id === $currentDefaultCardId,
                'owned' => $ownedAmounts[$card->id] ?? null,
            ])
            ->values()
            ->all();
    }
}
