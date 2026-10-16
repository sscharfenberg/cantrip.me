<?php

namespace Tests\Unit\Models;

use App\Models\OracleCard;
use App\Models\OracleCardFace;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * {@see OracleCard::isModalLand()} tells the deck stats that a card whose
 * front says "Sorcery" can still be played as a land. Without it the Karsten
 * table would read Bala Ged Recovery as a ritual and count it for nothing.
 */
class OracleCardModalLandTest extends TestCase
{
    #[Test]
    public function a_modal_card_with_a_land_back_is_a_modal_land(): void
    {
        $this->assertTrue($this->card('modal_dfc', ['Sorcery', 'Land'])->isModalLand());
    }

    #[Test]
    public function a_transform_card_with_a_land_back_is_not(): void
    {
        // Search for Azcanta has to flip before it taps for mana.
        $this->assertFalse($this->card('transform', ['Legendary Enchantment', 'Legendary Land'])->isModalLand());
    }

    #[Test]
    public function a_modal_card_with_two_spell_faces_is_not(): void
    {
        $this->assertFalse($this->card('modal_dfc', ['Legendary Creature — God', 'Legendary Artifact'])->isModalLand());
    }

    #[Test]
    public function a_land_front_alone_does_not_make_it_one(): void
    {
        // Only the back counts; a land front is already a land by its type line.
        $this->assertFalse($this->card('modal_dfc', ['Land', 'Sorcery'])->isModalLand());
    }

    /**
     * @param  list<string>  $typeLines  One per face, front first.
     */
    private function card(string $layout, array $typeLines): OracleCard
    {
        $card = new OracleCard(['layout' => $layout]);
        $faces = collect($typeLines)->map(fn (string $typeLine, int $index) => new OracleCardFace([
            'face_index' => $index,
            'type_line' => $typeLine,
        ]));
        $card->setRelation('faces', $faces);

        return $card;
    }
}
