<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Enums\Scryfall\ScryfallCardLayout;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Unit\Companions\BuildsTestDecks;

/**
 * Shared in-memory fixtures for the Rulebreaker unit tests.
 *
 * Distinct from {@see BuildsTestDecks} because these
 * rules read the DENORMALISED `oracle_cards.type_line` rather than the face
 * rows, and because they need a command zone and a deck colour identity — the
 * companion helpers deliberately build a deck with neither.
 *
 * Everything is hydrated in memory with relations pre-set, so profiles run
 * without touching a database.
 */
trait BuildsRulebreakerDecks
{
    protected function makeOracleCard(
        string $name,
        string $typeLine,
        ?string $colorIdentity = null,
        float $cmc = 1.0,
        ScryfallCardLayout $layout = ScryfallCardLayout::Normal,
    ): OracleCard {
        $card = new OracleCard;
        $card->id = (string) Str::uuid();
        $card->name = $name;
        $card->type_line = $typeLine;
        $card->color_identity = $colorIdentity;
        $card->cmc = $cmc;
        $card->layout = $layout;

        return $card;
    }

    protected function makeDeckCard(OracleCard $oracle, DeckZone $zone = DeckZone::Main, int $quantity = 1): DeckCard
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
     * Build a Commander deck led by `$commander`, holding `$deckCards`.
     *
     * @param  array<int, DeckCard>  $deckCards
     */
    protected function makeDeck(
        ?OracleCard $commander,
        array $deckCards = [],
        ?string $colors = null,
        ?string $rulebreakerColor = null,
    ): Deck {
        $deck = new Deck;
        $deck->id = (string) Str::uuid();
        $deck->format = CardFormat::Commander;
        $deck->colors = $colors;
        $deck->rulebreaker_color = $rulebreakerColor;

        $commandZone = $commander === null
            ? []
            : [$this->makeDeckCard($commander, DeckZone::Command)];

        $deck->setRelation('commanders', new Collection($commandZone));
        $deck->setRelation('deckCards', new Collection(array_merge($commandZone, $deckCards)));

        return $deck;
    }

    /** Tolabow himself: mono-blue legendary creature. */
    protected function tolabow(): OracleCard
    {
        return $this->makeOracleCard('Tolabow, Loch Rascal', 'Legendary Creature — Otter', 'U', 4.0);
    }
}
