<?php

namespace Tests\Feature;

use App\Enums\Scryfall\ScryfallRelatedComponent;
use App\Models\DefaultCard;
use App\Models\DefaultCardRelation;
use App\Models\OracleCard;
use App\Models\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the DefaultCard ↔ DefaultCardRelation wiring used by the
 * deck view to surface the matching token / meld / combo-piece printing
 * for a deck card. Verifies the eager-loadable accessors
 * (`tokens`, `meldParts`, `meldResults`, `comboPieces`) filter on the
 * `component` column correctly and that the row component is hydrated
 * back as a typed enum.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class DefaultCardRelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
    }

    #[Test]
    public function tokens_accessor_returns_only_token_relations(): void
    {
        $set = $this->makeSet();
        $bitterblossom = $this->makeDefaultCard('Bitterblossom', $set);
        $faerieRogueToken = $this->makeDefaultCard('Faerie Rogue', $set);
        $combo = $this->makeDefaultCard('Combo Buddy', $set);

        $this->makeRelation($bitterblossom, $faerieRogueToken, ScryfallRelatedComponent::Token);
        $this->makeRelation($bitterblossom, $combo, ScryfallRelatedComponent::ComboPiece);

        $tokens = $bitterblossom->tokens()->get();

        $this->assertCount(1, $tokens);
        $this->assertSame($faerieRogueToken->id, $tokens->first()->related_default_card_id);
        $this->assertSame(ScryfallRelatedComponent::Token, $tokens->first()->component);
    }

    #[Test]
    public function meld_accessors_split_on_component(): void
    {
        $set = $this->makeSet();
        $bruna = $this->makeDefaultCard('Bruna, the Fading Light', $set);
        $gisela = $this->makeDefaultCard('Gisela, the Broken Blade', $set);
        $brisela = $this->makeDefaultCard('Brisela, Voice of Nightmares', $set);

        $this->makeRelation($bruna, $gisela, ScryfallRelatedComponent::MeldPart);
        $this->makeRelation($bruna, $brisela, ScryfallRelatedComponent::MeldResult);

        $this->assertSame(
            [$gisela->id],
            $bruna->meldParts()->pluck('related_default_card_id')->all()
        );
        $this->assertSame(
            [$brisela->id],
            $bruna->meldResults()->pluck('related_default_card_id')->all()
        );
        $this->assertCount(0, $bruna->tokens()->get());
        $this->assertCount(0, $bruna->comboPieces()->get());
    }

    #[Test]
    public function relations_cascade_delete_with_source_card(): void
    {
        $set = $this->makeSet();
        $source = $this->makeDefaultCard('Source', $set);
        $related = $this->makeDefaultCard('Related', $set);

        $this->makeRelation($source, $related, ScryfallRelatedComponent::Token);
        $this->assertSame(1, DefaultCardRelation::query()->count());

        $source->delete();

        $this->assertSame(0, DefaultCardRelation::query()->count());
        $this->assertNotNull($related->fresh(), 'related card must survive');
    }

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);
    }

    private function makeDefaultCard(string $name, Set $set): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeRelation(
        DefaultCard $source,
        DefaultCard $related,
        ScryfallRelatedComponent $component,
    ): void {
        DefaultCardRelation::insert([
            'source_default_card_id' => $source->id,
            'related_default_card_id' => $related->id,
            'component' => $component->value,
        ]);
    }
}
