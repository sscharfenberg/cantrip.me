<?php

namespace App\Services;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Enums\Currency;
use App\Http\Controllers\Api\CardStackPreviewController;
use App\Models\DefaultCard;

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
            'legalities' => collect(CardFormat::cases())->map(function (CardFormat $format) use ($card) {
                $match = $card->oracle?->legalities->first(fn ($l) => $l->format === $format->value);

                return [
                    'format' => $format->value,
                    'legality' => $match?->legality->value ?? CardLegality::NotLegal->value,
                ];
            })->all(),
            'rulings' => $card->oracle?->rulings
                ->sortBy('published_at')
                ->values()
                ->map(fn ($ruling) => [
                    'source' => $ruling->source->value,
                    'published_at' => $ruling->published_at?->toDateString(),
                    'comment' => $ruling->comment,
                ])
                ->all() ?? [],
        ];
    }
}
