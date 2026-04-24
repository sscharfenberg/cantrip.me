<?php

namespace Tests\Unit\Companions;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shared helpers for CompanionProfile unit tests.
 *
 * Builds deck/deckCard/oracleCard/face objects in-memory with relations
 * pre-hydrated so profiles can validate without hitting the DB.
 */
trait BuildsTestDecks
{
    /**
     * Build an OracleCard with a single face. Pass `faces` for multi-faced
     * cards as `[['type_line' => ..., 'mana_cost' => ..., 'oracle_text' => ...], ...]`.
     *
     * @param  array<int, array{type_line?: string, mana_cost?: string, oracle_text?: string}>|null  $faces
     */
    protected function makeOracleCard(
        string $name,
        float $cmc,
        string $typeLine,
        string $manaCost = '',
        string $oracleText = '',
        ?array $faces = null,
    ): OracleCard {
        $card = new OracleCard;
        $card->id = (string) Str::uuid();
        $card->name = $name;
        $card->cmc = $cmc;

        if ($faces === null) {
            $faces = [[
                'type_line' => $typeLine,
                'mana_cost' => $manaCost,
                'oracle_text' => $oracleText,
            ]];
        }

        $faceModels = [];
        foreach ($faces as $index => $faceData) {
            $face = new OracleCardFace;
            $face->id = (string) Str::uuid();
            $face->oracle_card_id = $card->id;
            $face->face_index = $index;
            $face->type_line = $faceData['type_line'] ?? '';
            $face->mana_cost = $faceData['mana_cost'] ?? '';
            $face->oracle_text = $faceData['oracle_text'] ?? '';
            $faceModels[] = $face;
        }
        $card->setRelation('faces', new Collection($faceModels));

        return $card;
    }

    /**
     * Build a DeckCard for the given oracle card in the main zone (default)
     * with `quantity` copies.
     */
    protected function makeDeckCard(OracleCard $oracle, int $quantity = 1, DeckZone $zone = DeckZone::Main): DeckCard
    {
        $deckCard = new DeckCard;
        $deckCard->id = (string) Str::uuid();
        $deckCard->oracle_card_id = $oracle->id;
        $deckCard->quantity = $quantity;
        $deckCard->zone = $zone;
        $deckCard->setRelation('oracleCard', $oracle);

        return $deckCard;
    }

    /**
     * Build a Deck with the given deck cards and no commanders.
     *
     * @param  array<int, DeckCard>  $deckCards
     */
    protected function makeDeck(array $deckCards, CardFormat $format = CardFormat::Legacy): Deck
    {
        $deck = new Deck;
        $deck->id = (string) Str::uuid();
        $deck->format = $format;
        $deck->setRelation('deckCards', new Collection($deckCards));
        $deck->setRelation('commanders', new Collection);

        return $deck;
    }
}
