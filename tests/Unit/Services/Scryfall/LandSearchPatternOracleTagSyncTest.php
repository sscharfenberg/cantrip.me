<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\OracleTagSyncs\BooleanOracleTagSync;
use App\Services\Scryfall\OracleTagSyncs\LandSearchPatternOracleTagSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see LandSearchPatternOracleTagSync}: the non-land
 * counterpart of the fetchland sync. Oracle texts are verbatim Scryfall
 * payloads, because the parser keys on Scryfall's exact wording.
 */
class LandSearchPatternOracleTagSyncTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function classifiedSpells(): array
    {
        return [
            'typed single type (Three Visits)' => [
                'Search your library for a Forest card, put it onto the battlefield, then shuffle.',
                'typed:G',
            ],
            'typed type list, WUBRG-sorted (Farseek)' => [
                'Search your library for a Plains, Island, Swamp, or Mountain card, put it onto the battlefield tapped, then shuffle.',
                'typed:WUBR',
            ],
            'basic, split between battlefield and hand (Cultivate)' => [
                'Search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle.',
                'basic',
            ],
            'basic on a creature (Sakura-Tribe Elder)' => [
                'Sacrifice this creature: Search your library for a basic land card, put that card onto the battlefield tapped, then shuffle.',
                'basic',
            ],
            'any land, to hand (Expedition Map)' => [
                '{2}, {T}, Sacrifice this artifact: Search your library for a land card, reveal it, put it into your hand, then shuffle.',
                'any',
            ],
            'destination in a later sentence (Verdant Mastery)' => [
                "You may pay {3}{G} rather than pay this spell's mana cost.\nSearch your library for up to four basic land cards and reveal them. Put one of them onto the battlefield tapped under an opponent's control if the {3}{G} cost was paid. Put two of them onto the battlefield tapped under your control and the rest into your hand. Then shuffle.",
                'basic',
            ],
            'destination two sentences on (Tithe)' => [
                'Search your library for a Plains card. If target opponent controls more lands than you, you may search your library for an additional Plains card. Reveal those cards, put them into your hand, then shuffle.',
                'typed:W',
            ],
            'to hand on a saga chapter, which Karsten counts as ramp (The Birth of Meletis)' => [
                "(As this Saga enters and after your draw step, add a lore counter. Sacrifice after III.)\nI — Search your library for a basic Plains card, reveal it, put it into your hand, then shuffle.\nII — Create a 0/4 colorless Wall artifact creature token with defender.\nIII — You gain 2 life.",
                'basic:W',
            ],
            'two basic clauses, each with its own "basic" (Yasharn, Implacable Earth)' => [
                "When Yasharn enters, search your library for a basic Forest card and a basic Plains card, reveal those cards, put them into your hand, then shuffle.\nPlayers can't pay life or sacrifice nonland permanents to cast spells or activate abilities.",
                'basic:WG',
            ],
            'any land with a basic land type is typed, not any (Perilous Forays)' => [
                '{1}, Sacrifice a creature: Search your library for a land card with a basic land type, put it onto the battlefield tapped, then shuffle.',
                'typed:WUBRG',
            ],
            'a type list ending in a non-basic type (The Cave of Two Lovers)' => [
                "(As this Saga enters and after your draw step, add a lore counter. Sacrifice after III.)\nI — Create two 1/1 white Ally creature tokens.\nII — Search your library for a Mountain or Cave card, reveal it, put it into your hand, then shuffle.\nIII — Earthbend 3. (Target land you control becomes a 0/0 creature with haste that's still a land. Put three +1/+1 counters on it. When it dies or is exiled, return it to the battlefield tapped.)",
                'typed:R',
            ],
            'land as one of two card types (Archdruid\'s Charm)' => [
                "Choose one —\n• Search your library for a creature or land card and reveal it. Put it onto the battlefield tapped if it's a land card. Otherwise, put it into your hand. Then shuffle.\n• Put a +1/+1 counter on target creature you control. It deals damage equal to its power to target creature you don't control.\n• Exile target artifact or enchantment.",
                'any',
            ],
            'library and graveyard (Journey for the Elixir)' => [
                'Search your library and graveyard for a basic land card and a card named Jiang Yanggu, reveal them, put them into your hand, then shuffle.',
                'basic',
            ],
            'more zones in another order (made up)' => [
                'When this creature enters, search your graveyard, hand, and/or library for a basic land card, put it into your hand, then shuffle.',
                'basic',
            ],
            'library and/or graveyard (Captain Kirk, Boldly Going)' => [
                "Vigilance\nSpacecraft creatures you control get +3/+3.\nWhenever Captain Kirk enters or attacks, search your library and/or graveyard for a basic land card or a card named Starship Enterprise, reveal it, and put it into your hand. If you search your library this way, shuffle.",
                'basic',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchesThatSupplyNoColour(): array
    {
        return [
            'exiled (Strata Scythe)' => [
                "Imprint — When this Equipment enters, search your library for a land card, exile it, then shuffle.\nEquipped creature gets +1/+1 for each land on the battlefield with the same name as the exiled card.\nEquip {3}",
            ],
            'stacked on the library (Treefolk Harbinger)' => [
                'When this creature enters, you may search your library for a Treefolk or Forest card, reveal it, then shuffle and put that card on top.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('searchesThatSupplyNoColour')]
    public function it_skips_a_search_that_never_reaches_the_battlefield_or_hand(string $oracleText): void
    {
        $this->assertNull((new LandSearchPatternOracleTagSync)->deriveValue(['oracle_text' => $oracleText]));
    }

    #[Test]
    #[DataProvider('classifiedSpells')]
    public function it_classifies_what_a_spell_can_find(string $oracleText, string $expected): void
    {
        $sync = new LandSearchPatternOracleTagSync;

        $this->assertSame($expected, $sync->deriveValue(['oracle_text' => $oracleText]));
    }

    #[Test]
    public function it_reads_the_search_from_a_later_face(): void
    {
        // Bloomvine Regent // Claim Territory: the search is on the omen
        // half, and multi-faced payloads carry no top-level oracle_text.
        $card = [
            'card_faces' => [
                ['oracle_text' => "Flying\nWhenever this creature or another Dragon you control enters, you gain 3 life."],
                ['oracle_text' => 'Search your library for up to two basic Forest cards, reveal them, put one onto the battlefield tapped and the other into your hand, then shuffle. (Also shuffle this card.)'],
            ],
        ];

        $this->assertSame('basic:G', (new LandSearchPatternOracleTagSync)->deriveValue($card));
    }

    #[Test]
    public function it_ignores_a_land_type_named_only_in_reminder_text(): void
    {
        // Last Light of Durin's Day searches for a Dragon; the only Mountain
        // card it can find is through Mountaincycling, which is not ramp.
        $card = ['oracle_text' => "Whenever a Mountain you control enters, put a quest counter on this enchantment. If it has six or more quest counters on it, sacrifice it. If you do, search your hand and/or library for a Dragon card and put it onto the battlefield. If you search your library this way, shuffle.\nMountaincycling {2} ({2}, Discard this card: Search your library for a Mountain card, reveal it, put it into your hand, then shuffle.)"];

        $this->assertNull((new LandSearchPatternOracleTagSync)->deriveValue($card));
    }

    #[Test]
    public function it_narrows_the_tag_to_non_lands_that_search_their_own_library(): void
    {
        $sync = new LandSearchPatternOracleTagSync;

        $this->assertSame('otag:tutor-land -type:land o:"search your library"', $sync->query());
        $this->assertSame('land_search_pattern', $sync->column());
        $this->assertNull($sync->clearValue());
        $this->assertTrue($sync->expectsUnclassified());
    }

    #[Test]
    public function a_sync_that_does_not_narrow_searches_its_bare_tag(): void
    {
        $sync = new BooleanOracleTagSync(tag: 'mass-land-denial', column: 'mld');

        $this->assertSame('otag:mass-land-denial', $sync->query());
        $this->assertFalse($sync->expectsUnclassified());
    }
}
