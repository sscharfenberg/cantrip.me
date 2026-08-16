<?php

namespace Tests\Unit\Models;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see Deck::colorIdentity()} is the single source both the legality check
 * and the card-search filter read, so that search cannot offer a card the
 * validator will then flag.
 *
 * Before it existed the two disagreed: `DeckValidator` derived the identity
 * from the command zone while `DeckCardSearchService` read `decks.colors`, and
 * that column folds in the COMPANION's colours as well.
 */
class DeckColorIdentityTest extends TestCase
{
    #[Test]
    public function it_reads_the_stored_column_when_populated(): void
    {
        $deck = $this->makeDeck('UR', commanderIdentities: ['U']);

        $this->assertSame('UR', $deck->colorIdentity());
    }

    /**
     * The column is written by
     * `DeckCardService::recalculateColorsFromCommandZone()` as `$merged ?: null`,
     * so a colourless commander and a deck that was never recalculated are
     * indistinguishable at the column. Treating NULL as "colourless" outright
     * would flag every coloured card in the second case, so the command zone
     * decides instead.
     */
    #[Test]
    public function it_falls_back_to_the_command_zone_when_the_column_is_null(): void
    {
        $deck = $this->makeDeck(null, commanderIdentities: ['G', 'W']);

        $this->assertSame('WG', $deck->colorIdentity());
    }

    #[Test]
    public function it_falls_back_when_the_column_is_an_empty_string(): void
    {
        $deck = $this->makeDeck('', commanderIdentities: ['B']);

        $this->assertSame('B', $deck->colorIdentity());
    }

    /**
     * A genuinely colourless commander yields '' either way, so the fallback
     * costs a lookup and changes nothing — which is the acceptable half of the
     * NULL ambiguity.
     */
    #[Test]
    public function a_colorless_commander_resolves_to_the_empty_identity(): void
    {
        $deck = $this->makeDeck(null, commanderIdentities: [null]);

        $this->assertSame('', $deck->colorIdentity());
    }

    #[Test]
    public function a_deck_with_no_command_zone_resolves_to_the_empty_identity(): void
    {
        $deck = $this->makeDeck(null, commanderIdentities: []);

        $this->assertSame('', $deck->colorIdentity());
    }

    /**
     * WUBRG order, not the order the commanders happen to sit in — the value
     * is compared against `oracle_cards.color_identity`, which Scryfall emits
     * in that order, and is fed to a `^[WUBRG]*$` character class.
     */
    #[Test]
    public function the_fallback_orders_letters_wubrg(): void
    {
        $deck = $this->makeDeck(null, commanderIdentities: ['GR', 'W']);

        $this->assertSame('WRG', $deck->colorIdentity());
    }

    #[Test]
    public function the_fallback_deduplicates_overlapping_partner_identities(): void
    {
        $deck = $this->makeDeck(null, commanderIdentities: ['UB', 'BR']);

        $this->assertSame('UBR', $deck->colorIdentity());
    }

    /**
     * `decks.colors` is written from `zone IN (command, companion)`, so it can
     * in principle report colours the command zone alone does not justify —
     * which would silently widen the legality check.
     *
     * It cannot happen through the app: `SetDeckCompanionRequest` rejects a
     * companion outside the commander's identity, so the union always equals
     * the commander's. This pins the value that arrangement produces, so that
     * if the companion validation is ever relaxed the coupling fails here
     * rather than by quietly legalising a deck.
     */
    #[Test]
    public function a_companion_within_the_commanders_identity_does_not_widen_it(): void
    {
        // Lurrus (WB) under a WB commander: legal, and the union is a no-op.
        $deck = $this->makeDeck('WB', commanderIdentities: ['WB']);

        $this->assertSame('WB', $deck->colorIdentity());
    }

    /**
     * @param  array<int, string|null>  $commanderIdentities
     */
    private function makeDeck(?string $colors, array $commanderIdentities): Deck
    {
        $deck = new Deck;
        $deck->id = (string) Str::uuid();
        $deck->format = CardFormat::Commander;
        $deck->colors = $colors;

        $rows = [];
        foreach ($commanderIdentities as $identity) {
            $oracle = new OracleCard;
            $oracle->id = (string) Str::uuid();
            $oracle->name = 'Commander '.count($rows);
            $oracle->color_identity = $identity;

            $row = new DeckCard;
            $row->id = (string) Str::uuid();
            $row->oracle_card_id = $oracle->id;
            $row->zone = DeckZone::Command;
            $row->quantity = 1;
            $row->setRelation('oracleCard', $oracle);
            $rows[] = $row;
        }
        $deck->setRelation('commanders', new Collection($rows));

        return $deck;
    }
}
