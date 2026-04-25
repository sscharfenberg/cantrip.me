<?php

namespace App\Services;

use App\Enums\ContainerType;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\DefaultCard;

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
     * }>
     */
    public static function listForOracle(
        string|int $userId,
        string $oracleCardId,
        ?string $currentDefaultCardId,
    ): array {
        $printings = DefaultCard::query()
            ->with(['set:id,name,code,path', 'artist:id,name'])
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('default_cards.oracle_id', $oracleCardId)
            ->orderBy('sets.released_at', 'desc')
            ->orderBy('default_cards.id', 'desc')
            ->select('default_cards.*')
            ->get();

        $availableIds = CardStack::query()
            ->leftJoin('containers', 'card_stacks.container_id', '=', 'containers.id')
            ->where('card_stacks.user_id', $userId)
            ->whereIn('card_stacks.default_card_id', $printings->pluck('id')->all())
            ->where(function ($query): void {
                $query->whereNull('card_stacks.container_id')
                    ->orWhere('containers.type', '!=', ContainerType::Deckbox->value);
            })
            ->pluck('card_stacks.default_card_id')
            ->unique()
            ->flip();

        return $printings
            ->map(fn (DefaultCard $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'card_image_0' => $card->card_image_0,
                'card_image_1' => $card->card_image_1,
                'artist' => $card->artist?->name,
                'cn' => $card->collector_number,
                'finishes' => Finish::labelsFromMask($card->finishes),
                'set' => $card->set ? [
                    'name' => $card->set->name,
                    'code' => $card->set->code,
                    'path' => $card->set->path,
                ] : null,
                'in_collection' => $availableIds->has($card->id),
                'is_current' => $card->id === $currentDefaultCardId,
            ])
            ->values()
            ->all();
    }
}
