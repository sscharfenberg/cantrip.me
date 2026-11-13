<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\RelationRetargeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see RelationRetargeter}. The ambiguous names are the
 * ones Scryfall's default_cards bulk actually has: "Treasure" is a token
 * and a "Card" of the MKM front-card promos, "Dragon" names 8 token
 * oracles. On the 2026-11-12 bulk, 13 of the 2,139 orphan edges had such
 * a name; picking by name alone pointed them at an arbitrary card.
 */
class RelationRetargeterTest extends TestCase
{
    private RelationRetargeter $retargeter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->retargeter = new RelationRetargeter([
            $this->printing('treasure-token', 'treasure', 'Token Artifact — Treasure', 'tsnc', 'p-treasure-token'),
            $this->printing('treasure-card', 'treasure', 'Card', 'wmkm', 'p-treasure-card'),
            $this->printing('dragon-4-4', 'dragon', 'Token Creature — Dragon', 'twar', 'p-dragon-war'),
            $this->printing('dragon-5-5', 'dragon', 'Token Creature — Dragon', 'tm19', 'p-dragon-m19'),
            $this->printing('dragon-2-2', 'dragon', 'Token Creature — Dragon', 'tc17', 'p-dragon-c17'),
            $this->printing('sol-ring', 'sol ring', 'Artifact', 'lea', 'p-sol-ring-b'),
            $this->printing('sol-ring', 'sol ring', 'Artifact', 'c21', 'p-sol-ring-a'),
        ]);
    }

    #[Test]
    public function a_name_with_one_card_behind_it_resolves_to_its_lowest_printing(): void
    {
        $this->assertSame('p-sol-ring-a', $this->retargeter->retarget('Sol Ring', 'Artifact', 'xyz'));
    }

    #[Test]
    public function the_printing_in_the_source_cards_set_wins_over_the_lowest_one(): void
    {
        $this->assertSame('p-sol-ring-b', $this->retargeter->retarget('Sol Ring', 'Artifact', 'lea'));
    }

    #[Test]
    public function the_type_line_tells_a_token_from_a_card_of_the_same_name(): void
    {
        $this->assertSame('p-treasure-token', $this->retargeter->retarget('Treasure', 'Token Artifact — Treasure', 'xyz'));
    }

    #[Test]
    public function a_type_line_matching_nothing_falls_back_to_the_name(): void
    {
        $this->assertSame('p-sol-ring-a', $this->retargeter->retarget('Sol Ring', 'Legendary Artifact', 'xyz'));
    }

    #[Test]
    public function the_source_cards_token_set_settles_a_name_several_tokens_share(): void
    {
        // Sarkhan the Masterless (WAR) makes the 4/4 Dragon of TWAR.
        $this->assertSame('p-dragon-war', $this->retargeter->retarget('Dragon', 'Token Creature — Dragon', 'war'));
    }

    #[Test]
    public function a_name_still_ambiguous_after_every_step_is_dropped(): void
    {
        // A Sarkhan promo (PWAR): no Dragon token in pwar or tpwar.
        $this->assertNull($this->retargeter->retarget('Dragon', 'Token Creature — Dragon', 'pwar'));
    }

    #[Test]
    public function two_candidates_left_in_the_token_set_are_still_ambiguous(): void
    {
        $retargeter = new RelationRetargeter([
            $this->printing('spirit-1-1-white', 'spirit', 'Token Creature — Spirit', 'tkhm', 'p-spirit-w'),
            $this->printing('spirit-1-1-black', 'spirit', 'Token Creature — Spirit', 'tkhm', 'p-spirit-b'),
        ]);

        $this->assertNull($retargeter->retarget('Spirit', 'Token Creature — Spirit', 'khm'));
    }

    #[Test]
    public function an_unknown_or_missing_name_is_dropped(): void
    {
        $this->assertNull($this->retargeter->retarget('No Such Card', 'Artifact', 'xyz'));
        $this->assertNull($this->retargeter->retarget(null, 'Artifact', 'xyz'));
    }

    private function printing(string $oracleId, string $name, string $typeLine, string $set, string $printingId): object
    {
        return (object) [
            'oracle_id' => $oracleId,
            'searchable_name' => $name,
            'type_line' => $typeLine,
            'set_code' => $set,
            'default_card_id' => $printingId,
        ];
    }
}
