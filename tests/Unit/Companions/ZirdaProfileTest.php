<?php

namespace Tests\Unit\Companions;

use App\Companions\ZirdaProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ZirdaProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_every_permanent_has_an_activated_ability(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard(
                'Llanowar Elves', 1.0, 'Creature — Elf Druid', '{G}',
                '{T}: Add {G}.',
            )),
            $this->makeDeckCard($this->makeOracleCard(
                'Karn, the Great Creator', 4.0, 'Legendary Planeswalker — Karn', '{4}',
                "+1: Until your next turn, up to one target noncreature artifact becomes an artifact creature...\n−2: You may reveal an artifact card you own from outside the game or choose...",
            )),
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant', '{R}', 'Lightning Bolt deals 3 damage to any target.')),
        ]);

        $this->assertNull((new ZirdaProfile)->validate($deck));
    }

    #[Test]
    public function keyword_activated_abilities_pass_even_without_a_colon(): void
    {
        $deck = $this->makeDeck([
            // Crew reminder text stripped — only the keyword remains, but the
            // keyword alone is enough.
            $this->makeDeckCard($this->makeOracleCard(
                'Smuggler\'s Copter', 2.0, 'Artifact — Vehicle', '{2}',
                "Flying\nWhen this vehicle attacks or blocks, you may draw a card. Then discard a card.\nCrew 1",
            )),
        ]);

        $this->assertNull((new ZirdaProfile)->validate($deck));
    }

    #[Test]
    public function flags_permanent_without_activated_ability(): void
    {
        $vanilla = $this->makeOracleCard(
            'Grizzly Bears', 2.0, 'Creature — Bear', '{1}{G}',
            '',
        );
        $offending = $this->makeDeckCard($vanilla);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard(
                'Llanowar Elves', 1.0, 'Creature — Elf Druid', '{G}',
                '{T}: Add {G}.',
            )),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new ZirdaProfile)->validate($deck));
    }

    #[Test]
    public function does_not_flag_nonpermanents(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard(
                'Lightning Bolt', 1.0, 'Instant', '{R}',
                'Lightning Bolt deals 3 damage to any target.',
            )),
        ]);

        $this->assertNull((new ZirdaProfile)->validate($deck));
    }
}
