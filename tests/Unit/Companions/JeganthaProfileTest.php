<?php

namespace Tests\Unit\Companions;

use App\Companions\JeganthaProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JeganthaProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_every_colored_pip_is_unique_per_face(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant', '{R}')),
            $this->makeDeckCard($this->makeOracleCard('Esper Charm', 3.0, 'Instant', '{W}{U}{B}')),
            $this->makeDeckCard($this->makeOracleCard('Sol Ring', 1.0, 'Artifact', '{1}')),
            $this->makeDeckCard($this->makeOracleCard('Forest', 0.0, 'Basic Land — Forest')),
        ]);

        $this->assertNull((new JeganthaProfile)->validate($deck));
    }

    #[Test]
    public function flags_card_with_two_of_the_same_color(): void
    {
        $fireblast = $this->makeOracleCard('Fireblast', 6.0, 'Instant', '{4}{R}{R}');
        $offending = $this->makeDeckCard($fireblast);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant', '{R}')),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new JeganthaProfile)->validate($deck));
    }

    #[Test]
    public function hybrid_pips_count_toward_both_colors(): void
    {
        // Kitchen Finks: {1}{G/W}{G/W} — G appears twice AND W appears twice.
        $finks = $this->makeOracleCard('Kitchen Finks', 3.0, 'Creature — Ouphe', '{1}{G/W}{G/W}');
        $offending = $this->makeDeckCard($finks);
        $deck = $this->makeDeck([$offending]);

        $this->assertSame(['card_ids' => [$offending->id]], (new JeganthaProfile)->validate($deck));
    }

    #[Test]
    public function phyrexian_pip_counts_as_its_colored_component(): void
    {
        // {W/P}{W} → W appears twice.
        $gideon = $this->makeOracleCard('Gideon, Ally of Zendikar', 4.0, 'Legendary Planeswalker — Gideon', '{W/P}{W}');
        $offending = $this->makeDeckCard($gideon);
        $deck = $this->makeDeck([$offending]);

        $this->assertSame(['card_ids' => [$offending->id]], (new JeganthaProfile)->validate($deck));
    }

    #[Test]
    public function split_card_faces_are_checked_independently(): void
    {
        // Fire // Ice: {1}{R} on one face, {1}{U} on the other. Each face only
        // has one of any color, so the card as a whole is legal — even though
        // combining both costs would show R=1, U=1 (still fine here anyway).
        $fireIce = $this->makeOracleCard(
            'Fire // Ice', 4.0, 'Instant // Instant',
            faces: [
                ['type_line' => 'Instant', 'mana_cost' => '{1}{R}'],
                ['type_line' => 'Instant', 'mana_cost' => '{1}{U}'],
            ],
        );
        $deck = $this->makeDeck([$this->makeDeckCard($fireIce)]);

        $this->assertNull((new JeganthaProfile)->validate($deck));
    }

    #[Test]
    public function split_card_is_flagged_when_a_single_face_duplicates_a_color(): void
    {
        $bad = $this->makeOracleCard(
            'Bad // Split', 4.0, 'Instant // Instant',
            faces: [
                ['type_line' => 'Instant', 'mana_cost' => '{R}{R}'],
                ['type_line' => 'Instant', 'mana_cost' => '{1}{U}'],
            ],
        );
        $offending = $this->makeDeckCard($bad);
        $deck = $this->makeDeck([$offending]);

        $this->assertSame(['card_ids' => [$offending->id]], (new JeganthaProfile)->validate($deck));
    }
}
