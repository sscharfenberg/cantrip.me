<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end test of the deck CSV export endpoint.
 *
 * Covers the whole pipeline: route auth (private vs public, owner vs
 * non-owner), header order, role row ordering (commanders → companion →
 * deck cards), partner flag rendering, multi-claim comma-join, and the
 * privacy guarantee that non-owners exporting a public deck never see
 * `Card Stack ID` values.
 */
class DeckCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    #[Test]
    public function owner_export_includes_headers_and_all_role_rows(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);

        $sol = $this->makeOracleCard('Sol Ring');
        $solPrint = $this->makeDefaultCard($sol, 'cmd', '263');
        $deckCard = $this->makeDeckCard($deck, $sol, $solPrint, 1);

        $atraxa = $this->makeOracleCard('Atraxa, Praetors\' Voice');
        $atraxaPrint = $this->makeDefaultCard($atraxa, 'cmr', '347');
        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $atraxa->id,
            'default_card_id' => $atraxaPrint->id,
            'zone' => DeckZone::Command->value,
            'role' => DeckCardRole::Commander->value,
            'quantity' => 1,
        ]);

        $lurrus = $this->makeOracleCard('Lurrus of the Dream-Den');
        $lurrusPrint = $this->makeDefaultCard($lurrus, 'iko', '226');
        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $lurrus->id,
            'default_card_id' => $lurrusPrint->id,
            'zone' => DeckZone::Companion->value,
            'role' => DeckCardRole::Companion->value,
            'quantity' => 1,
        ]);

        $rows = $this->exportRows($owner, $deck);
        $this->assertSame([
            'Role', 'Deck Card ID', 'Scryfall ID', 'Name', 'Edition', 'Collector Number',
            'Count', 'Zone', 'Category', 'Is Partner', 'Card Stack ID',
        ], $rows[0]);

        // Roles emitted in order: commanders → companion → cards. Role
        // and Zone columns mirror the deck_cards row exactly.
        $this->assertSame('commander', $rows[1][0]);
        $this->assertSame('Atraxa, Praetors\' Voice', $rows[1][3]);
        $this->assertSame('CMR', $rows[1][4]);
        $this->assertSame('command', $rows[1][7]);
        $this->assertSame('false', $rows[1][9]);
        $this->assertSame('', $rows[1][10]);

        $this->assertSame('companion', $rows[2][0]);
        $this->assertSame('Lurrus of the Dream-Den', $rows[2][3]);
        $this->assertSame('IKO', $rows[2][4]);
        $this->assertSame('companion', $rows[2][7]);

        $this->assertSame('card', $rows[3][0]);
        $this->assertSame($deckCard->id, $rows[3][1]);
        $this->assertSame('Sol Ring', $rows[3][3]);
        $this->assertSame('CMD', $rows[3][4]);
        $this->assertSame('main', $rows[3][7]);
    }

    #[Test]
    public function multi_claim_card_stack_ids_are_comma_joined_and_sorted(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $oracle = $this->makeOracleCard('Lightning Bolt');
        $printing = $this->makeDefaultCard($oracle, 'lea', '161');
        $deckCard = $this->makeDeckCard($deck, $oracle, $printing, 4);

        $stackB = $this->makeCardStack($owner, $printing, amount: 2, idPrefix: 'b');
        $stackA = $this->makeCardStack($owner, $printing, amount: 2, idPrefix: 'a');
        $deckCard->cardStacks()->attach([$stackB->id, $stackA->id]);

        $rows = $this->exportRows($owner, $deck);
        $cardRow = $rows[1];

        $this->assertSame('card', $cardRow[0]);
        $this->assertSame((string) 4, $cardRow[6]);
        // Stack ids are joined and asc-sorted, so the 'a-…' uuid lands first.
        $this->assertSame($stackA->id.','.$stackB->id, $cardRow[10]);
    }

    #[Test]
    public function partner_commander_row_renders_with_is_partner_true(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);

        $primaryOracle = $this->makeOracleCard('Akiri, Line-Slinger');
        $primaryPrint = $this->makeDefaultCard($primaryOracle, 'cmr', '236');
        $partnerOracle = $this->makeOracleCard('Bruse Tarl, Boorish Herder');
        $partnerPrint = $this->makeDefaultCard($partnerOracle, 'cmr', '237');

        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $primaryOracle->id,
            'default_card_id' => $primaryPrint->id,
            'zone' => DeckZone::Command->value,
            'role' => DeckCardRole::Commander->value,
            'quantity' => 1,
        ]);
        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $partnerOracle->id,
            'default_card_id' => $partnerPrint->id,
            'zone' => DeckZone::Command->value,
            'role' => DeckCardRole::Partner->value,
            'quantity' => 1,
        ]);

        $rows = $this->exportRows($owner, $deck);
        // Primary commander → Role='commander', Is Partner=false.
        $this->assertSame('commander', $rows[1][0]);
        $this->assertSame('Akiri, Line-Slinger', $rows[1][3]);
        $this->assertSame('command', $rows[1][7]);
        $this->assertSame('false', $rows[1][9]);
        // Partner → Role='partner' (real role string, not 'commander'),
        // Is Partner=true (kept for backward-compat with old importers).
        $this->assertSame('partner', $rows[2][0]);
        $this->assertSame('Bruse Tarl, Boorish Herder', $rows[2][3]);
        $this->assertSame('command', $rows[2][7]);
        $this->assertSame('true', $rows[2][9]);
    }

    #[Test]
    public function deck_card_category_name_appears_in_category_column(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $cat = DeckCategory::create([
            'deck_id' => $deck->id,
            'name' => 'Ramp',
        ]);

        $oracle = $this->makeOracleCard('Cultivate');
        $printing = $this->makeDefaultCard($oracle, 'm21', '177');
        $deckCard = $this->makeDeckCard($deck, $oracle, $printing, 1);
        $deckCard->update(['category_id' => $cat->id]);

        $rows = $this->exportRows($owner, $deck);
        $this->assertSame('Ramp', $rows[1][8]);
    }

    #[Test]
    public function private_deck_returns_404_for_non_owner(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $stranger = $this->makeUser();

        $this->actingAs($stranger)
            ->get('/decks/'.$deck->id.'/export')
            ->assertNotFound();
    }

    #[Test]
    public function public_deck_is_exportable_by_non_owner_with_blanked_card_stack_ids(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Public);
        $oracle = $this->makeOracleCard('Counterspell');
        $printing = $this->makeDefaultCard($oracle, 'lea', '54');
        $deckCard = $this->makeDeckCard($deck, $oracle, $printing, 1);
        $stack = $this->makeCardStack($owner, $printing);
        $deckCard->cardStacks()->attach($stack->id);

        $stranger = $this->makeUser();
        $rows = $this->exportRows($stranger, $deck);

        $cardRow = $rows[1];
        $this->assertSame('card', $cardRow[0]);
        // Owner-private pivot reference is blanked out.
        $this->assertSame('', $cardRow[10]);
    }

    #[Test]
    public function public_deck_export_for_owner_still_includes_card_stack_ids(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Public);
        $oracle = $this->makeOracleCard('Brainstorm');
        $printing = $this->makeDefaultCard($oracle, 'ice', '61');
        $deckCard = $this->makeDeckCard($deck, $oracle, $printing, 1);
        $stack = $this->makeCardStack($owner, $printing);
        $deckCard->cardStacks()->attach($stack->id);

        $rows = $this->exportRows($owner, $deck);
        $this->assertSame($stack->id, $rows[1][10]);
    }

    /**
     * Hit the export endpoint as the given user and parse the CSV body
     * into a rows array. Strips the UTF-8 BOM the service emits so
     * column 0 of the header row reads as plain `Role`.
     *
     * @return array<int, array<int, string>>
     */
    private function exportRows(User $user, Deck $deck): array
    {
        $response = $this->actingAs($user)
            ->get('/decks/'.$deck->id.'/export');

        $response->assertOk();

        $body = $response->streamedContent();
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $body);
        rewind($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeDeck(User $user, ContainerVisibility $visibility): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
            'visibility' => $visibility->value,
        ]);
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
            'cmc' => 1,
            'color_identity' => 'C',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle, string $setCode, string $collectorNumber): DefaultCard
    {
        $set = Set::firstOrCreate(
            ['code' => $setCode],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Test Set '.strtoupper($setCode),
                'released_at' => '2026-01-01',
                'card_count' => 1,
                'set_type' => 'expansion',
                'scryfall_uri' => 'https://example.com/set/'.$setCode,
                'path' => $setCode,
            ]
        );

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => $collectorNumber,
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeDeckCard(Deck $deck, OracleCard $oracle, DefaultCard $default, int $quantity): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
        ]);
    }

    /**
     * @param  string|null  $idPrefix  Force the leading character of the
     *                                 generated UUID. Used by the
     *                                 multi-claim sort test so we can
     *                                 assert deterministic order.
     */
    private function makeCardStack(User $user, DefaultCard $default, ?Container $container = null, int $amount = 1, ?string $idPrefix = null): CardStack
    {
        $id = (string) Str::uuid();
        if ($idPrefix !== null) {
            $id = $idPrefix.substr($id, 1);
        }

        $stack = new CardStack;
        $stack->id = $id;
        $stack->user_id = $user->id;
        $stack->default_card_id = $default->id;
        $stack->container_id = $container?->id;
        $stack->amount = $amount;
        $stack->finish = 1;
        $stack->language = 'en';
        $stack->save();

        return $stack;
    }
}
