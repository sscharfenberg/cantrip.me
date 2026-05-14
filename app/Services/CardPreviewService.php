<?php

namespace App\Services;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Enums\Currency;
use App\Http\Controllers\Api\CardStackPreviewController;
use App\Models\CardStack;
use App\Models\DefaultCard;
use App\Models\User;

/**
 * Builds the card-level preview payload shared by the stack preview
 * endpoint and the deck-card preview endpoint.
 *
 * The returned shape mirrors the card-level fields of the
 * {@see CardStackPreviewController} response:
 * name, images, set, artist, collector_number, single-card price for
 * the requested currency (nonfoil), scryfall_uri and the full
 * legalities matrix. Stack-specific extras (`amount`, `condition`,
 * `finish`, `language`, `created_at`, `updated_at`, `total_price`,
 * `claims`) live in the stack controller and are merged on top.
 */
class CardPreviewService
{
    /**
     * Build the card-level preview payload for the given default card.
     *
     * Caller is responsible for eager-loading `set`, `artist`,
     * `oracle.legalities`, and `oracle.rulings` to avoid N+1 queries.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(DefaultCard $card, Currency $currency): array
    {
        $priceColumn = 'price_'.$currency->value;

        return [
            'name' => $card->name,
            'card_image_0' => $card->card_image_0,
            'card_image_1' => $card->card_image_1,
            'set_code' => $card->set?->code,
            'set_name' => $card->set?->name,
            'set_path' => $card->set?->path,
            'collector_number' => $card->collector_number,
            'artist' => $card->artist?->name,
            'price' => (float) ($card->{$priceColumn} ?? 0),
            'scryfall_uri' => $card->oracle?->scryfall_uri,
            'produced_mana' => $card->oracle?->produced_mana
                ? str_split($card->oracle->produced_mana)
                : null,
            'is_game_changer' => (bool) $card->oracle?->game_changer,
            'is_mld' => (bool) $card->oracle?->mld,
            'legalities' => collect(CardFormat::cases())->map(function (CardFormat $format) use ($card) {
                $match = $card->oracle?->legalities->first(fn ($l) => $l->format === $format->value);

                return [
                    'format' => $format->value,
                    'legality' => $match?->legality->value ?? CardLegality::NotLegal->value,
                ];
            })->all(),
            'rulings' => $card->oracle?->rulings
                ->sortByDesc('published_at')
                ->values()
                ->map(fn ($ruling) => [
                    'source' => $ruling->source->value,
                    'published_at' => $ruling->published_at?->toDateString(),
                    'comment' => $ruling->comment,
                ])
                ->all() ?? [],
        ];
    }

    /**
     * Build the viewer-collection block for the preview modal.
     *
     * Returns two lists from the viewer's own collection:
     *  - `same_printing`: every stack the user owns whose
     *    `default_card_id` equals the previewed card. One row per
     *    stack — distinct finishes/conditions of the same printing
     *    therefore appear on separate rows.
     *  - `other_printings`: every stack the user owns of a
     *    *different* printing of the same oracle card (same
     *    `oracle_id`, different `default_card_id`). One row per
     *    stack.
     *
     * Always tied to the authenticated viewer — never to the stack
     * owner — so a viewer browsing someone else's public stack still
     * sees what they personally own. Returns empty arrays when the
     * user owns nothing relevant.
     *
     * @return array{
     *     same_printing: list<array{container_name: string|null, amount: int}>,
     *     other_printings: list<array{
     *         default_card_id: string,
     *         set_code: string|null,
     *         set_name: string|null,
     *         set_path: string|null,
     *         collector_number: string,
     *         card_image_0: string|null,
     *         container_name: string|null,
     *         amount: int
     *     }>
     * }
     */
    public static function collectionInfoFor(DefaultCard $card, User $user): array
    {
        $samePrinting = CardStack::query()
            ->where('user_id', $user->id)
            ->where('default_card_id', $card->id)
            ->with('container:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (CardStack $stack) => [
                'container_name' => $stack->container?->name,
                'amount' => $stack->amount,
            ])
            ->values()
            ->all();

        $otherPrintings = [];
        if ($card->oracle_id !== null) {
            $otherPrintings = CardStack::query()
                ->where('user_id', $user->id)
                ->where('default_card_id', '!=', $card->id)
                ->whereHas('defaultCard', fn ($q) => $q->where('oracle_id', $card->oracle_id))
                ->with([
                    'container:id,name',
                    'defaultCard:id,collector_number,set_id,card_image_0,oracle_id',
                    'defaultCard.set:id,code,name,path',
                ])
                ->orderBy('created_at')
                ->get()
                ->map(fn (CardStack $stack) => [
                    'default_card_id' => $stack->default_card_id,
                    'set_code' => $stack->defaultCard?->set?->code,
                    'set_name' => $stack->defaultCard?->set?->name,
                    'set_path' => $stack->defaultCard?->set?->path,
                    'collector_number' => $stack->defaultCard?->collector_number ?? '',
                    'card_image_0' => $stack->defaultCard?->card_image_0,
                    'container_name' => $stack->container?->name,
                    'amount' => $stack->amount,
                ])
                ->values()
                ->all();
        }

        return [
            'same_printing' => $samePrinting,
            'other_printings' => $otherPrintings,
        ];
    }
}
