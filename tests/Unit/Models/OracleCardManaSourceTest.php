<?php

namespace Tests\Unit\Models;

use App\Models\OracleCard;
use App\Models\OracleCardFace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * {@see OracleCard::manaSource()} separates the cards Scryfall lists with
 * `produced_mana` into the three kinds Karsten weighs differently. Oracle
 * texts are verbatim Scryfall front faces.
 */
class OracleCardManaSourceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function frontFaces(): array
    {
        return [
            'mana creature (Llanowar Elves)' => ['G', '{T}: Add {G}.', 'ability'],
            'mana rock (Arcane Signet)' => ['WUBRG', "{T}: Add one mana of any color in your commander's color identity.", 'ability'],
            'land aura, "adds" (Utopia Sprawl)' => [
                'WUBRG',
                "Enchant Forest\nAs this Aura enters, choose a color.\nWhenever enchanted Forest is tapped for mana, its controller adds an additional one mana of the chosen color.",
                'ability',
            ],
            'ritual (Dark Ritual)' => ['B', 'Add {B}{B}{B}.', 'ability'],
            'Treasure maker whose reminder text says "Add" (Smothering Tithe)' => [
                'WUBRG',
                "Whenever an opponent draws a card, that player may pay {2}. If the player doesn't, you create a Treasure token. (It's an artifact with \"{T}, Sacrifice this token: Add one mana of any color.\")",
                'token',
            ],
            'Treasure maker (Dockside Extortionist)' => [
                'WUBRG',
                'When this creature enters, create X Treasure tokens, where X is the number of artifacts and enchantments your opponents control.',
                'token',
            ],
            'mana only after transforming (Search for Azcanta)' => [
                'U',
                'At the beginning of your upkeep, surveil 1. Then if you have seven or more cards in your graveyard, you may transform Search for Azcanta. (Look at the top card of your library. You may put that card into your graveyard.)',
                null,
            ],
            'no produced mana at all (Rampant Growth)' => [
                '',
                'Search your library for a basic land card, put that card onto the battlefield tapped, then shuffle.',
                null,
            ],
        ];
    }

    #[Test]
    #[DataProvider('frontFaces')]
    public function it_classifies_where_the_mana_comes_from(string $producedMana, string $frontText, ?string $expected): void
    {
        $card = new OracleCard(['layout' => 'normal', 'produced_mana' => $producedMana === '' ? null : $producedMana]);
        $card->setRelation('faces', collect([new OracleCardFace(['face_index' => 0, 'oracle_text' => $frontText])]));

        $this->assertSame($expected, $card->manaSource());
    }
}
