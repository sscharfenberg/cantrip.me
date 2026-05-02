<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that {@see DeckValidator} surfaces the `commander_banned`
 * violation when a deck's commander is on the active format's
 * banned-as-commander overlay (Duel Commander has its own list for
 * cards that are legal in the 99 but banned from the command zone).
 *
 * Skipped on the staging suite — uses RefreshDatabase + a small set
 * of synthetic OracleCard rows.
 */
class DeckValidatorCommanderBanTest extends TestCase
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
    public function flags_breya_as_banned_commander_in_duel(): void
    {
        $user = User::factory()->create();
        $breya = $this->makeOracleCard('Breya, Etherium Shaper');
        $deck = $this->makeDeck($user->id, CardFormat::Duel);
        $deck->commanders()->attach($breya->id, [
            'default_card_id' => $this->makeDefaultCard($breya)->id,
            'is_partner' => false,
        ]);
        $deck->load(['commanders', 'companion', 'deckCards']);

        $violations = DeckValidator::validate($deck);

        $banned = collect($violations)->firstWhere('type', 'commander_banned');
        $this->assertNotNull($banned, 'expected a commander_banned violation');
        $this->assertSame(['Breya, Etherium Shaper'], $banned['names']);
    }

    #[Test]
    public function does_not_flag_breya_in_regular_commander(): void
    {
        $user = User::factory()->create();
        $breya = $this->makeOracleCard('Breya, Etherium Shaper');
        $deck = $this->makeDeck($user->id, CardFormat::Commander);
        $deck->commanders()->attach($breya->id, [
            'default_card_id' => $this->makeDefaultCard($breya)->id,
            'is_partner' => false,
        ]);
        $deck->load(['commanders', 'companion', 'deckCards']);

        $violations = DeckValidator::validate($deck);

        $this->assertNull(
            collect($violations)->firstWhere('type', 'commander_banned'),
            'commander_banned must not fire outside Duel Commander'
        );
    }

    private function makeOracleCard(string $name): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 4,
            'color_identity' => 'WUBR',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
    }

    private function makeDeck(string $userId, CardFormat $format): Deck
    {
        return Deck::create([
            'user_id' => $userId,
            'name' => 'Test Deck',
            'format' => $format->value,
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle): DefaultCard
    {
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
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
}
