<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\CardFormat;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whtz, the Bibliophile's "no maximum deck size" and the ADD path.
 *
 * `canAddCopy()` used to take the deck size plus a `$maxDeckSizeLifted` flag,
 * which is how Whtz got past the 100-card ceiling there. The ceiling has since
 * left the add path for every deck — building past the maximum and cutting
 * down is allowed, and DeckValidator reports the oversized deck — so Whtz needs
 * no lift at the add path any more. What it must still not do is relax the copy
 * rules.
 *
 * Separate from {@see RulebreakerProfilesTest} because `canAddCopy()` calls
 * `OracleCard::hasUnlimitedCopiesRule()`, whose `loadMissing()` resolves a
 * database connection even when the relation is already hydrated — so this
 * needs a booted application, which the plain-PHPUnit profile tests do not
 * have. No queries actually run: the faces relation is pre-set.
 */
class RulebreakerDeckSizeTest extends TestCase
{
    use BuildsRulebreakerDecks;

    /**
     * Commander keeps its 100-card maximum as a legality rule, yet the add
     * check never asks about it: there is no deck size for it to compare.
     */
    #[Test]
    public function the_maximum_deck_size_is_not_an_add_rule(): void
    {
        $profile = CardFormat::Commander->rules();
        $card = $this->makeOracleCard('Filler', 'Creature — Human', 'W');
        $card->setRelation('faces', new Collection);

        $this->assertSame(100, $profile->maxDeckSize(), 'Commander still has a ceiling to validate against');
        $this->assertTrue($profile->canAddCopy($card, 0)->allowed, 'adding is never refused for it');
    }

    /** Lifting the size ceiling never touched singleton, and still does not. */
    #[Test]
    public function singleton_still_refuses_a_second_copy(): void
    {
        $profile = CardFormat::Commander->rules();
        $card = $this->makeOracleCard('Filler', 'Creature — Human', 'W');
        $card->setRelation('faces', new Collection);

        $this->assertFalse(
            $profile->canAddCopy($card, 1)->allowed,
            'a second copy is still a singleton violation',
        );
    }
}
