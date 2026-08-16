<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\CardFormat;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whtz, the Bibliophile's "no maximum deck size" reaching the ADD path.
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
     * Suppressing the `deck_size_max` VIOLATION was not enough on its own. The
     * deck read as legal while the add endpoint went on refusing the 101st
     * card and quick-add filtered it out of the results, which left the card's
     * only ability unreachable.
     */
    #[Test]
    public function the_deck_size_lift_reaches_can_add_copy(): void
    {
        $profile = CardFormat::Commander->rules();
        $card = $this->makeOracleCard('Filler', 'Creature — Human', 'W');
        $card->setRelation('faces', new Collection);

        $this->assertFalse(
            $profile->canAddCopy($card, 0, 100)->allowed,
            'an ordinary Commander deck is capped at 100',
        );
        $this->assertTrue(
            $profile->canAddCopy($card, 0, 100, maxDeckSizeLifted: true)->allowed,
            'Whtz lifts that cap at the add path too',
        );
    }

    /** The lift is about size only — singleton is untouched. */
    #[Test]
    public function the_deck_size_lift_does_not_relax_singleton(): void
    {
        $profile = CardFormat::Commander->rules();
        $card = $this->makeOracleCard('Filler', 'Creature — Human', 'W');
        $card->setRelation('faces', new Collection);

        $this->assertFalse(
            $profile->canAddCopy($card, 1, 100, maxDeckSizeLifted: true)->allowed,
            'a second copy is still a singleton violation',
        );
    }
}
