<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\OracleTagSyncs\FetchPatternOracleTagSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see FetchPatternOracleTagSync}. Unlike the land-search
 * sync it hands the parser a land's whole front-face text — no search
 * window, no reminder stripping — so a rule that reads fine on a lone
 * search sentence has to hold up against everything else a land says.
 * Real cards are verbatim Scryfall payloads; the made-up ones probe a
 * wording the rules must not trip over.
 */
class FetchPatternOracleTagSyncTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function lands(): array
    {
        return [
            'typed, two types (Polluted Delta)' => [
                '{T}, Pay 1 life, Sacrifice this land: Search your library for an Island or Swamp card, put it onto the battlefield, then shuffle.',
                'typed:UB',
            ],
            'typed, two clauses (Krosan Verge)' => [
                "This land enters tapped.\n{T}: Add {C}.\n{2}, {T}, Sacrifice this land: Search your library for a Forest card and a Plains card, put them onto the battlefield tapped, then shuffle.",
                'typed:WG',
            ],
            'basic of three types (Bant Panorama)' => [
                "{T}: Add {C}.\n{1}, {T}, Sacrifice this land: Search your library for a basic Forest, Plains, or Island card, put it onto the battlefield tapped, then shuffle.",
                'basic:WUG',
            ],
            'any basic (Fabled Passage)' => [
                '{T}, Sacrifice this land: Search your library for a basic land card, put it onto the battlefield tapped, then shuffle. Then if you control four or more lands, untap that land.',
                'basic',
            ],
            'a land with a basic land type (Potential Horizon)' => [
                "If you were the starting player, this land enters tapped.\n{T}, Sacrifice this land: Search your library for a land card with a basic land type, put it onto the battlefield, then shuffle.",
                'typed:WUBRG',
            ],
            'a basic type named outside the search is not fetched (made up)' => [
                "{T}, Sacrifice this land: Search your library for a basic Forest card, put it onto the battlefield tapped, then shuffle.\nWhen this land is put into a graveyard, you may reveal a basic Plains card from your hand.",
                'basic:G',
            ],
            'a longer list closed by another type (made up)' => [
                '{T}, Sacrifice this land: Search your library for a Plains, Island, or Cave card, put it onto the battlefield, then shuffle.',
                'typed:WU',
            ],
            'land as one of three card types (made up)' => [
                '{T}, Sacrifice this land: Search your library for an artifact, creature, or land card, reveal it, put it into your hand, then shuffle.',
                'any',
            ],
            '"or more cards" is not a type list (made up)' => [
                'Whenever you sacrifice a Forest or more cards leave your graveyard, draw a card.',
                null,
            ],
        ];
    }

    #[Test]
    #[DataProvider('lands')]
    public function it_classifies_what_a_land_can_fetch(string $oracleText, ?string $expected): void
    {
        $this->assertSame($expected, (new FetchPatternOracleTagSync)->deriveValue(['oracle_text' => $oracleText]));
    }
}
