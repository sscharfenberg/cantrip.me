<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\OracleCardLegality;
use App\Models\Set;
use App\Models\User;
use App\Services\CardNameNormalizer;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\DeckListParserTest;

/**
 * The deck list import: parse + resolve, the replacement search, and the
 * confirm that creates the deck.
 *
 * Resolution is driven through the parse endpoint, so every assertion is on
 * what the review page actually receives. The parser's dialect rules have
 * their own unit test ({@see DeckListParserTest}); here
 * the pastes stay minimal and the fixtures are chosen so the rules under test
 * disagree — a free printing that is *not* the newest, a set that holds an
 * older printing, a stack held by a deck that is not the one being built.
 */
class DeckListImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        // The name ranking every card search shares orders by CHAR_LENGTH,
        // which MariaDB has and SQLite lacks. Registering it here lets the
        // suggestion and search paths run under the fast suite.
        DB::connection()->getPdo()->sqliteCreateFunction('CHAR_LENGTH', fn ($value): int => mb_strlen((string) $value), 1);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function set(string $code, string $releasedAt): Set
    {
        return Set::firstOrCreate(['code' => $code], [
            'id' => (string) Str::uuid(),
            'name' => 'Set '.strtoupper($code),
            'released_at' => $releasedAt,
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set/'.$code,
            'path' => $code,
        ]);
    }

    /**
     * @param  list<array{name?: string, type_line?: string, oracle_text?: string, power?: string, toughness?: string, loyalty?: string}>  $faces
     * @param  array<string, string>  $legalities  format → legality; defaults to legal in every format used here.
     */
    private function oracle(string $name, string $colorIdentity = 'R', array $faces = [], ?array $legalities = null): OracleCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => CardNameNormalizer::normalize($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => $colorIdentity,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);

        foreach ($faces === [] ? [['type_line' => 'Instant']] : $faces as $index => $face) {
            OracleCardFace::create([
                'id' => (string) Str::uuid(),
                'oracle_card_id' => $oracle->id,
                'face_index' => $index,
                'name' => $face['name'] ?? $name,
                'type_line' => $face['type_line'] ?? 'Instant',
                'oracle_text' => $face['oracle_text'] ?? null,
                'power' => $face['power'] ?? null,
                'toughness' => $face['toughness'] ?? null,
                'loyalty' => $face['loyalty'] ?? null,
            ]);
        }

        $legalities ??= ['legacy' => 'legal', 'commander' => 'legal', 'oathbreaker' => 'legal', 'vintage' => 'legal'];
        foreach ($legalities as $format => $legality) {
            OracleCardLegality::create(['oracle_card_id' => $oracle->id, 'format' => $format, 'legality' => $legality]);
        }

        return $oracle;
    }

    private function legendaryCreature(string $name, string $colorIdentity = 'R', ?string $oracleText = null): OracleCard
    {
        return $this->oracle($name, $colorIdentity, [[
            'type_line' => 'Legendary Creature — Goblin Warrior',
            'oracle_text' => $oracleText,
            'power' => '3',
            'toughness' => '3',
        ]]);
    }

    private function printing(OracleCard $oracle, Set $set, string $number): DefaultCard
    {
        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => $number,
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function user(bool $collection = true): User
    {
        return User::factory()->create(['collection_integration_enabled' => $collection]);
    }

    private function stack(User $user, DefaultCard $printing, int $amount, ?Container $container = null): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container?->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    private function deck(User $user, ?Container $deckbox = null): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Existing Deck',
            'format' => CardFormat::Legacy->value,
            'container_id' => $deckbox?->id,
        ]);
    }

    private function container(User $user): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => 'Box '.Str::random(4),
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
    }

    private function parse(User $user, string $text, CardFormat $format = CardFormat::Legacy): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/decks/import-list/parse', [
            'format' => $format->value,
            'text' => $text,
        ]);
    }

    /** The first resolved line of a parse — most tests paste one card. */
    private function firstLine(User $user, string $text, CardFormat $format = CardFormat::Legacy): array
    {
        return $this->parse($user, $text, $format)->assertOk()->json('lines.0');
    }

    // ── access and validation ─────────────────────────────────────────────

    #[Test]
    public function the_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->user())
            ->get('/decks/import-list')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Deck/Import/DeckListImportPage')->has('formats')->where('maxLines', 500));
    }

    #[Test]
    public function guests_are_turned_away_everywhere(): void
    {
        $this->get('/decks/import-list')->assertRedirect();
        $this->postJson('/api/decks/import-list/parse', ['format' => 'legacy', 'text' => '1 Sol Ring'])->assertUnauthorized();
        $this->getJson('/api/decks/import-list/search?format=legacy&q=sol')->assertUnauthorized();
        $this->postJson('/decks/import-list', [])->assertUnauthorized();
    }

    #[Test]
    public function parse_needs_a_format_and_some_text(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/decks/import-list/parse', ['format' => 'not-a-format', 'text' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format', 'text']);
    }

    #[Test]
    public function parse_refuses_more_than_five_hundred_lines(): void
    {
        $text = implode("\n", array_fill(0, 501, '1 Sol Ring'));

        $this->parse($this->user(), $text)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text']);
    }

    #[Test]
    public function blank_lines_do_not_count_toward_the_limit(): void
    {
        $text = implode("\n\n", array_fill(0, 500, '1 Sol Ring'));

        $this->parse($this->user(), $text)->assertOk();
    }

    // ── finding the card ─────────────────────────────────────────────────

    #[Test]
    public function set_and_number_pin_the_printing_even_unowned(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $old = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $new = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->stack($user, $new, 4);

        $line = $this->firstLine($user, '4 Lightning Bolt (LEA) 161');

        $this->assertSame('resolved', $line['status']);
        $this->assertSame($old->id, $line['card']['default_card_id']);
        $this->assertSame(['lea', '161'], [$line['card']['set_code'], $line['card']['collector_number']]);
        $this->assertSame([], $line['notices']);
    }

    #[Test]
    public function a_number_that_names_another_card_falls_back_to_the_name(): void
    {
        $user = $this->user();
        $lea = $this->set('lea', '1993-08-05');
        $bolt = $this->oracle('Lightning Bolt');
        $boltPrint = $this->printing($bolt, $lea, '161');
        $this->printing($this->oracle('Shivan Dragon'), $lea, '174');

        $line = $this->firstLine($user, '1 Lightning Bolt (LEA) 174');

        $this->assertSame($boltPrint->id, $line['card']['default_card_id']);
        $this->assertSame(['printing_not_found'], $line['notices']);
    }

    #[Test]
    public function set_only_prefers_a_free_copy_inside_that_set(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $m10 = $this->set('m10', '2009-07-17');
        $m10a = $this->printing($bolt, $m10, '146');
        $m10b = $this->printing($bolt, $m10, '146s');
        $newest = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->stack($user, $m10a, 1);
        $this->stack($user, $newest, 9);

        $line = $this->firstLine($user, '1 Lightning Bolt (M10)');

        // 2X2 has more free copies but is outside the named set; inside it
        // the owned printing beats its unowned sibling.
        $this->assertSame($m10a->id, $line['card']['default_card_id']);
        $this->assertNotSame($m10b->id, $line['card']['default_card_id']);
    }

    #[Test]
    public function set_only_without_a_free_copy_takes_the_newest_in_the_set(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $m10 = $this->printing($bolt, $this->set('m10', '2009-07-17'), '146');
        $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');

        $line = $this->firstLine($user, '1 Lightning Bolt (M10)');

        $this->assertSame($m10->id, $line['card']['default_card_id']);
    }

    #[Test]
    public function a_set_without_the_card_is_noticed_and_ignored(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $newest = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->set('zen', '2009-10-02');

        $line = $this->firstLine($user, '1 Lightning Bolt (ZEN)');

        $this->assertSame($newest->id, $line['card']['default_card_id']);
        $this->assertSame(['printing_not_found'], $line['notices']);
    }

    #[Test]
    public function name_only_prefers_the_printing_with_most_free_copies(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $lea = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $m10 = $this->printing($bolt, $this->set('m10', '2009-07-17'), '146');
        $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->stack($user, $lea, 1);
        $this->stack($user, $m10, 3);

        $line = $this->firstLine($user, '4 Lightning Bolt');

        $this->assertSame($m10->id, $line['card']['default_card_id']);
    }

    #[Test]
    public function name_only_without_free_copies_takes_the_newest(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $newest = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');

        $this->assertSame($newest->id, $this->firstLine($user, 'Lightning Bolt')['card']['default_card_id']);
    }

    #[Test]
    public function copies_any_deck_holds_are_not_free(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $claimed = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $boxed = $this->printing($bolt, $this->set('m10', '2009-07-17'), '146');
        $newest = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');

        // Held explicitly: claimed by a row of an existing deck.
        $existing = $this->deck($user);
        $row = DeckCard::create(['deck_id' => $existing->id, 'oracle_card_id' => $bolt->id, 'default_card_id' => $claimed->id, 'zone' => 'main', 'quantity' => 1]);
        $row->cardStacks()->attach($this->stack($user, $claimed, 4)->id);
        // Held implicitly: sitting in another deck's deckbox.
        $box = $this->container($user);
        $this->deck($user, $box);
        $this->stack($user, $boxed, 4, $box);

        $line = $this->firstLine($user, '1 Lightning Bolt');

        $this->assertSame($newest->id, $line['card']['default_card_id']);
        $this->assertSame(['state' => 'unavailable', 'needed' => 1, 'exact' => 0, 'other' => 0, 'blocked' => 8], $line['availability']);
    }

    #[Test]
    public function the_front_face_name_finds_a_double_faced_card(): void
    {
        $user = $this->user();
        $delver = $this->oracle('Delver of Secrets // Insectile Aberration', 'U', [
            ['name' => 'Delver of Secrets', 'type_line' => 'Creature — Human Wizard'],
            ['name' => 'Insectile Aberration', 'type_line' => 'Creature — Human Insect'],
        ]);
        $this->printing($delver, $this->set('isd', '2011-09-30'), '51');

        $line = $this->firstLine($user, '4 Delver of Secrets');

        $this->assertSame($delver->id, $line['card']['oracle_card_id']);
    }

    #[Test]
    public function a_translated_name_finds_the_card(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        DB::table('oracle_card_translations')->insert([
            'oracle_card_id' => $bolt->id,
            'lang' => 'de',
            'printed_name' => 'Blitzschlag',
            'searchable_name' => 'blitzschlag',
        ]);

        $this->assertSame($bolt->id, $this->firstLine($user, '4 Blitzschlag')['card']['oracle_card_id']);
    }

    #[Test]
    public function of_two_cards_with_one_name_the_legal_one_is_taken(): void
    {
        $user = $this->user();
        $real = $this->oracle('Goblin Guide');
        $this->printing($real, $this->set('zen', '2009-10-02'), '126');
        $token = $this->oracle('Goblin Guide', 'R', [], ['legacy' => 'not_legal']);
        $this->printing($token, $this->set('tzen', '2009-10-02'), '1');

        $this->assertSame($real->id, $this->firstLine($user, '1 Goblin Guide')['card']['oracle_card_id']);
    }

    #[Test]
    public function two_legal_cards_with_one_name_are_ambiguous(): void
    {
        $user = $this->user();
        $a = $this->oracle('Goblin Guide');
        $this->printing($a, $this->set('zen', '2009-10-02'), '126');
        $b = $this->oracle('Goblin Guide');
        $this->printing($b, $this->set('j22', '2022-12-02'), '1');

        $line = $this->firstLine($user, '1 Goblin Guide');

        $this->assertSame(['unresolved', 'ambiguous'], [$line['status'], $line['reason']]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column(array_column($line['suggestions'], 'card'), 'oracle_card_id'));
    }

    #[Test]
    public function an_unknown_name_gets_suggestions_from_the_name_search(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $this->printing($this->oracle('Lightning Helix', 'RW'), $this->set('rav', '2005-10-07'), '213');
        $banned = $this->oracle('Lightning Bargain', 'R', [], ['legacy' => 'banned']);
        $this->printing($banned, $this->set('lea', '1993-08-05'), '999');

        // "Lightning Blot": the whole name matches nothing, so the longest
        // word alone is tried — and the banned card is not suggested.
        $line = $this->firstLine($user, '1 Lightning Blot');

        $this->assertSame(['unresolved', 'not_found'], [$line['status'], $line['reason']]);
        $this->assertSame(['Lightning Bolt', 'Lightning Helix'], array_column(array_column($line['suggestions'], 'card'), 'name'));
        $this->assertNotNull($line['suggestions'][0]['card']['default_card_id']);
    }

    #[Test]
    public function an_unparseable_line_is_unresolved_without_suggestions(): void
    {
        $line = $this->firstLine($this->user(), '0 Lightning Bolt');

        $this->assertSame(['unresolved', 'unparseable', []], [$line['status'], $line['reason'], $line['suggestions']]);
    }

    // ── availability and the master switch ───────────────────────────────

    #[Test]
    public function availability_reports_each_of_the_three_states(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $lea = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $m10 = $this->printing($bolt, $this->set('m10', '2009-07-17'), '146');
        $this->stack($user, $lea, 2);
        $this->stack($user, $m10, 1);

        $lines = $this->parse($user, "2 Lightning Bolt (LEA) 161\n4 Lightning Bolt (LEA) 161\n1 Shock")->assertOk()->json('lines');

        $this->assertSame(['state' => 'available', 'needed' => 2, 'exact' => 2, 'other' => 1, 'blocked' => 0], $lines[0]['availability']);
        $this->assertSame('partial', $lines[1]['availability']['state']);
        $this->assertSame('unresolved', $lines[2]['status']);
    }

    #[Test]
    public function with_the_master_switch_off_the_collection_is_invisible(): void
    {
        $user = $this->user(collection: false);
        $bolt = $this->oracle('Lightning Bolt');
        $owned = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $newest = $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->stack($user, $owned, 4);

        $response = $this->parse($user, '4 Lightning Bolt')->assertOk();

        $this->assertFalse($response->json('collection'));
        $this->assertSame($newest->id, $response->json('lines.0.card.default_card_id'));
        $this->assertNull($response->json('lines.0.availability'));
    }

    #[Test]
    public function availability_for_is_the_deck_pages_rule(): void
    {
        $free = ['a' => 2, 'b' => 3];

        $this->assertSame('available', DeckCollectionStatusService::availabilityFor($free, 0, 'a', 2)['state']);
        $this->assertSame('partial', DeckCollectionStatusService::availabilityFor($free, 0, 'a', 3)['state']);
        $this->assertSame('partial', DeckCollectionStatusService::availabilityFor(['b' => 3], 0, 'a', 1)['state']);
        $this->assertSame(['state' => 'unavailable', 'needed' => 1, 'exact' => 0, 'other' => 0, 'blocked' => 5], DeckCollectionStatusService::availabilityFor([], 5, 'a', 1));
        $this->assertSame(['state' => 'partial', 'needed' => 1, 'exact' => 0, 'other' => 5, 'blocked' => 0], DeckCollectionStatusService::availabilityFor($free, 0, null, 1));
    }

    // ── facts for client-side warnings ───────────────────────────────────

    #[Test]
    public function the_card_carries_legality_and_its_copy_limit(): void
    {
        $user = $this->user();
        $set = $this->set('lea', '1993-08-05');
        $this->printing($this->oracle('Lightning Bolt'), $set, '161');
        $this->printing($this->oracle('Mountain', 'C', [['type_line' => 'Basic Land — Mountain']]), $set, '292');
        $this->printing($this->oracle('Relentless Rats', 'B', [['type_line' => 'Creature — Rat', 'oracle_text' => 'A deck can have any number of cards named Relentless Rats.']]), $set, '10');
        $this->printing($this->oracle('Ancestral Recall', 'U', [], ['vintage' => 'restricted', 'legacy' => 'banned']), $set, '48');

        $legacy = $this->parse($user, "4 Lightning Bolt\n20 Mountain\n12 Relentless Rats\n1 Ancestral Recall")->assertOk()->json('lines');
        $vintage = $this->parse($user, '1 Ancestral Recall', CardFormat::Vintage)->assertOk()->json('lines.0.card');

        $this->assertSame([4, null, null, 4], array_column(array_column($legacy, 'card'), 'copy_limit'));
        $this->assertSame([true, true, true, false], array_column(array_column($legacy, 'card'), 'is_legal'));
        $this->assertSame([true, 1], [$vintage['is_legal'], $vintage['copy_limit']]);
    }

    #[Test]
    public function the_parse_carries_the_format_rules_and_the_maybeboard_count(): void
    {
        $response = $this->parse($this->user(), "1 Sol Ring\nMaybeboard\n1 Goblin Guide", CardFormat::Commander)->assertOk();

        $this->assertSame(1, $response->json('dropped'));
        $this->assertTrue($response->json('rules.requiresCommander'));
        $this->assertSame(1, $response->json('rules.maxCopies'));
    }

    // ── command zone ─────────────────────────────────────────────────────

    #[Test]
    public function the_commander_section_prefills_the_command_zone(): void
    {
        $user = $this->user();
        $set = $this->set('ddt', '2017-11-10');
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $this->printing($krenko, $set, '52');
        $this->printing($this->oracle('Sol Ring', 'C'), $set, '1');

        $response = $this->parse($user, "Commander\n1 Krenko, Mob Boss\n\nDeck\n1 Sol Ring", CardFormat::Commander)->assertOk();

        $this->assertSame($krenko->id, $response->json('command_zone.commander.id'));
        $this->assertNull($response->json('command_zone.partner'));
        $this->assertSame(['Sol Ring'], array_column($response->json('lines'), 'name'));
    }

    #[Test]
    public function a_partner_that_pairs_fills_the_second_slot(): void
    {
        $user = $this->user();
        $set = $this->set('cmr', '2020-11-20');
        $a = $this->legendaryCreature('Akiri, Fearless Voyager', 'RW', 'Partner');
        $b = $this->legendaryCreature('Thrasios, Triton Hero', 'GU', 'Partner');
        $this->printing($a, $set, '1');
        $this->printing($b, $set, '2');

        $response = $this->parse($user, "Commander\n1 Akiri, Fearless Voyager\n1 Thrasios, Triton Hero", CardFormat::Commander)->assertOk();

        $this->assertSame([$a->id, $b->id], [$response->json('command_zone.commander.id'), $response->json('command_zone.partner.id')]);
        $this->assertSame([], $response->json('lines'));
    }

    #[Test]
    public function the_pairing_is_found_whatever_order_the_paste_lists_it_in(): void
    {
        // Sites sort the command zone by name, so the second card often comes
        // first: a Background before its commander, a companion before its
        // Doctor. Neither may push the pair apart.
        $user = $this->user();
        $set = $this->set('clb', '2022-06-10');
        $wilson = $this->legendaryCreature('Wilson, Refined Grizzly', 'G', 'Choose a Background');
        $artisan = $this->oracle('Guild Artisan', 'R', [['type_line' => 'Legendary Enchantment — Background']]);
        $doctor = $this->oracle('The Tenth Doctor', 'URW', [[
            'type_line' => 'Legendary Creature — Time Lord Doctor', 'power' => '3', 'toughness' => '3',
        ]]);
        $rose = $this->legendaryCreature('Rose Tyler', 'W', "Doctor's companion");
        foreach ([$wilson, $artisan, $doctor, $rose] as $n => $card) {
            $this->printing($card, $set, (string) ($n + 1));
        }

        $background = $this->parse($user, "Commander\n1 Guild Artisan\n1 Wilson, Refined Grizzly", CardFormat::Commander)->assertOk();
        $companion = $this->parse($user, "Commander\n1 Rose Tyler\n1 The Tenth Doctor", CardFormat::Commander)->assertOk();

        $this->assertSame([$wilson->id, $artisan->id], [$background->json('command_zone.commander.id'), $background->json('command_zone.partner.id')]);
        $this->assertSame([$doctor->id, $rose->id], [$companion->json('command_zone.commander.id'), $companion->json('command_zone.partner.id')]);
        $this->assertSame([], $background->json('lines'));
        $this->assertSame([], $companion->json('lines'));
    }

    #[Test]
    public function the_prefilled_command_zone_keeps_the_pasted_printing(): void
    {
        $user = $this->user();
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $ddt = $this->printing($krenko, $this->set('ddt', '2017-11-10'), '52');
        $this->printing($krenko, $this->set('m13', '2012-07-13'), '139');
        $this->printing($krenko, $this->set('sld', '2024-01-01'), '9');

        $response = $this->parse($user, "Commander\n1 Krenko, Mob Boss (DDT) 52", CardFormat::Commander)->assertOk();

        $this->assertSame([$krenko->id => $ddt->id], $response->json('command_zone.printings'));
    }

    #[Test]
    public function an_ineligible_command_zone_card_falls_back_to_main(): void
    {
        $user = $this->user();
        $set = $this->set('ddt', '2017-11-10');
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $this->printing($krenko, $set, '52');
        // A second legendary creature without partner cannot share the zone.
        $this->printing($this->legendaryCreature('Zada, Hedron Grinder'), $set, '53');
        $this->printing($this->oracle('Goblin Guide'), $set, '54');

        $response = $this->parse($user, "Commander\n1 Krenko, Mob Boss\n1 Zada, Hedron Grinder\n1 Goblin Guide", CardFormat::Commander)->assertOk();

        $this->assertSame($krenko->id, $response->json('command_zone.commander.id'));
        $this->assertSame(['main', 'main'], array_column($response->json('lines'), 'zone'));
        $this->assertSame([['not_commander'], ['not_commander']], array_column($response->json('lines'), 'notices'));
    }

    #[Test]
    public function a_format_without_a_command_zone_just_keeps_the_cards(): void
    {
        $user = $this->user();
        $this->printing($this->legendaryCreature('Krenko, Mob Boss'), $this->set('ddt', '2017-11-10'), '52');

        $response = $this->parse($user, "Commander\n1 Krenko, Mob Boss")->assertOk();

        $this->assertNull($response->json('command_zone.commander'));
        $this->assertSame([['main', []]], array_map(fn (array $l): array => [$l['zone'], $l['notices']], $response->json('lines')));
    }

    #[Test]
    public function an_oathbreaker_and_a_spell_listed_first_are_both_prefilled(): void
    {
        $user = $this->user();
        $set = $this->set('war', '2019-05-03');
        $spell = $this->oracle('Lightning Bolt', 'R');
        $walker = $this->oracle('Chandra, Fire Artisan', 'R', [['type_line' => 'Legendary Planeswalker — Chandra', 'loyalty' => '4']]);
        $offColour = $this->oracle('Counterspell', 'U');
        $this->printing($spell, $set, '1');
        $this->printing($walker, $set, '2');
        $this->printing($offColour, $set, '3');

        $response = $this->parse($user, "Commander\n1 Lightning Bolt\n1 Chandra, Fire Artisan", CardFormat::Oathbreaker)->assertOk();
        $offResponse = $this->parse($user, "Commander\n1 Counterspell\n1 Chandra, Fire Artisan", CardFormat::Oathbreaker)->assertOk();

        $this->assertSame([$walker->id, $spell->id], [$response->json('command_zone.commander.id'), $response->json('command_zone.signature_spell.id')]);
        $this->assertNull($offResponse->json('command_zone.signature_spell'));
        $this->assertSame(['Counterspell'], array_column($offResponse->json('lines'), 'name'));
        $this->assertSame(['not_commander'], $offResponse->json('lines.0.notices'));
    }

    #[Test]
    public function only_one_companion_and_only_where_the_format_has_one(): void
    {
        $user = $this->user();
        $set = $this->set('iko', '2020-04-24');
        $this->printing($this->oracle('Lurrus of the Dream-Den', 'WB'), $set, '1');
        $this->printing($this->oracle('Jegantha, the Wellspring', 'R'), $set, '2');
        $paste = "Companion\n1 Lurrus of the Dream-Den\n1 Jegantha, the Wellspring";

        $legacy = $this->parse($user, $paste)->assertOk()->json('lines');
        $oathbreaker = $this->parse($user, $paste, CardFormat::Oathbreaker)->assertOk()->json('lines');

        $this->assertSame(['companion', 'side'], array_column($legacy, 'zone'));
        $this->assertSame(['side', 'side'], array_column($oathbreaker, 'zone'));
    }

    // ── replacement search ───────────────────────────────────────────────

    #[Test]
    public function search_returns_cards_with_their_printing_and_availability(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $owned = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $this->printing($bolt, $this->set('2x2', '2022-07-08'), '117');
        $this->stack($user, $owned, 1);
        $this->printing($this->oracle('Lightning Bargain', 'R', [], ['legacy' => 'banned']), $this->set('m21', '2020-07-03'), '9');

        $results = $this->actingAs($user)
            ->getJson('/api/decks/import-list/search?format=legacy&q=lightning&quantity=2')
            ->assertOk()
            ->json();

        // The search is not limited to the pool — illegal cards are flagged.
        $this->assertSame(['Lightning Bolt', 'Lightning Bargain'], array_column(array_column($results, 'card'), 'name'));
        $this->assertSame($owned->id, $results[0]['card']['default_card_id']);
        $this->assertSame(['partial', 2], [$results[0]['availability']['state'], $results[0]['availability']['needed']]);
        $this->assertFalse($results[1]['card']['is_legal']);
    }

    // ── confirm ──────────────────────────────────────────────────────────

    #[Test]
    public function confirm_creates_the_deck_with_its_command_zone_rows_and_categories(): void
    {
        $user = $this->user();
        $set = $this->set('ddt', '2017-11-10');
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $this->printing($krenko, $set, '52');
        $ring = $this->oracle('Sol Ring', 'C');
        $ringPrint = $this->printing($ring, $set, '1');
        $signet = $this->oracle('Arcane Signet', 'C');
        $signetPrint = $this->printing($signet, $set, '2');
        $mountain = $this->oracle('Mountain', 'C');
        $mountainPrint = $this->printing($mountain, $set, '3');

        $response = $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'commander',
            'deck_name' => 'Krenko Goblins',
            'commander_id' => $krenko->id,
            'rows' => [
                ['oracle_card_id' => $ring->id, 'default_card_id' => $ringPrint->id, 'quantity' => 1, 'zone' => 'main', 'category' => 'Ramp'],
                ['oracle_card_id' => $signet->id, 'default_card_id' => $signetPrint->id, 'quantity' => 1, 'zone' => 'main', 'category' => 'Ramp'],
                ['oracle_card_id' => $mountain->id, 'default_card_id' => $mountainPrint->id, 'quantity' => 20, 'zone' => 'main', 'category' => null],
                ['oracle_card_id' => $mountain->id, 'default_card_id' => $mountainPrint->id, 'quantity' => 9, 'zone' => 'main', 'category' => null],
            ],
        ])->assertOk();

        $deck = Deck::query()->where('user_id', $user->id)->sole();
        $response->assertJson(['redirect' => route('decks.show', $deck)]);
        $this->assertSame(['Krenko Goblins', CardFormat::Commander, 'R'], [$deck->name, $deck->format, $deck->colors]);
        $this->assertSame('success', session('type'));

        $commander = DeckCard::query()->where('deck_id', $deck->id)->where('zone', DeckZone::Command->value)->sole();
        $this->assertSame([$krenko->id, DeckCardRole::Commander], [$commander->oracle_card_id, $commander->role]);

        $categories = DeckCategory::query()->where('deck_id', $deck->id)->pluck('id', 'name')->all();
        $this->assertSame(['Ramp'], array_keys($categories));
        $main = DeckCard::query()->where('deck_id', $deck->id)->where('zone', 'main')->get()->keyBy('oracle_card_id');
        $this->assertSame([$categories['Ramp'], $categories['Ramp']], [$main[$ring->id]->category_id, $main[$signet->id]->category_id]);
        // The two Mountain lines merged into one row.
        $this->assertSame([29, null], [$main[$mountain->id]->quantity, $main[$mountain->id]->category_id]);
        $this->assertCount(3, $main);
    }

    #[Test]
    public function confirm_gives_the_command_zone_the_printing_the_paste_named(): void
    {
        $user = $this->user();
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $ddt = $this->printing($krenko, $this->set('ddt', '2017-11-10'), '52');
        $this->printing($krenko, $this->set('sld', '2024-01-01'), '9');

        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'commander',
            'deck_name' => 'Krenko',
            'commander_id' => $krenko->id,
            'command_zone_printings' => [$krenko->id => $ddt->id],
            'rows' => [],
        ])->assertOk();

        $commander = DeckCard::query()->where('zone', DeckZone::Command->value)->sole();
        $this->assertSame($ddt->id, $commander->default_card_id);
    }

    #[Test]
    public function confirm_refuses_a_command_zone_printing_that_does_not_fit(): void
    {
        $user = $this->user();
        $set = $this->set('ddt', '2017-11-10');
        $krenko = $this->legendaryCreature('Krenko, Mob Boss');
        $this->printing($krenko, $set, '52');
        $other = $this->oracle('Sol Ring', 'C');
        $otherPrint = $this->printing($other, $set, '1');
        $payload = fn (array $printings): array => [
            'format' => 'commander', 'deck_name' => 'X', 'commander_id' => $krenko->id,
            'command_zone_printings' => $printings, 'rows' => [],
        ];

        // A printing of another card, and a card that is not in the zone.
        $this->actingAs($user)->postJson('/decks/import-list', $payload([$krenko->id => $otherPrint->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(["command_zone_printings.{$krenko->id}"]);
        $this->actingAs($user)->postJson('/decks/import-list', $payload([$other->id => $otherPrint->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(["command_zone_printings.{$other->id}"]);

        $this->assertSame(0, Deck::query()->count());
    }

    #[Test]
    public function a_long_category_is_cut_to_fit_and_imports(): void
    {
        $user = $this->user();
        $ring = $this->oracle('Sol Ring', 'C');
        $ringPrint = $this->printing($ring, $this->set('c21', '2021-04-23'), '263');
        $header = str_repeat('Cards that win the game ', 4).':';

        $line = $this->firstLine($user, "{$header}\n1 Sol Ring");

        $this->assertLessThanOrEqual(DeckCategory::NAME_MAX, mb_strlen($line['category']));
        $this->assertGreaterThan(DeckCategory::NAME_MAX, mb_strlen($header));
        $this->assertStringStartsWith('Cards that win the game Cards', $line['category']);
        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'legacy',
            'deck_name' => 'X',
            'rows' => [['oracle_card_id' => $ring->id, 'default_card_id' => $ringPrint->id, 'quantity' => 1, 'zone' => 'main', 'category' => $line['category']]],
        ])->assertOk();
    }

    #[Test]
    public function confirm_writes_the_companion_and_the_sideboard(): void
    {
        $user = $this->user();
        $set = $this->set('iko', '2020-04-24');
        $lurrus = $this->oracle('Lurrus of the Dream-Den', 'WB');
        $lurrusPrint = $this->printing($lurrus, $set, '1');
        $pyro = $this->oracle('Pyroblast');
        $pyroPrint = $this->printing($pyro, $set, '2');

        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'legacy',
            'deck_name' => 'Lurrus',
            'rows' => [
                ['oracle_card_id' => $lurrus->id, 'default_card_id' => $lurrusPrint->id, 'quantity' => 1, 'zone' => 'companion', 'category' => 'Ignored'],
                ['oracle_card_id' => $pyro->id, 'default_card_id' => $pyroPrint->id, 'quantity' => 2, 'zone' => 'side'],
            ],
        ])->assertOk();

        $deck = Deck::query()->where('user_id', $user->id)->sole();
        $companion = DeckCard::query()->where('deck_id', $deck->id)->where('zone', 'companion')->sole();
        $this->assertSame(DeckCardRole::Companion, $companion->role);
        $this->assertSame(2, DeckCard::query()->where('deck_id', $deck->id)->where('zone', 'side')->sole()->quantity);
        $this->assertSame(0, DeckCategory::query()->where('deck_id', $deck->id)->count());
    }

    #[Test]
    public function confirm_holds_the_deck_fields_to_the_create_forms_rules(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'commander',
            'deck_name' => '',
            'bracket' => 2,
            'rows' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['deck_name', 'commander_id']);

        // `bracket` is prohibited outside formats with the Game Changer list,
        // exactly as on the create form.
        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'legacy',
            'deck_name' => 'X',
            'bracket' => 2,
            'rows' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['bracket']);

        $this->assertSame(0, Deck::query()->count());
    }

    #[Test]
    public function confirm_refuses_a_printing_of_another_card(): void
    {
        $user = $this->user();
        $set = $this->set('lea', '1993-08-05');
        $bolt = $this->oracle('Lightning Bolt');
        $dragonPrint = $this->printing($this->oracle('Shivan Dragon'), $set, '174');

        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'legacy',
            'deck_name' => 'X',
            'rows' => [['oracle_card_id' => $bolt->id, 'default_card_id' => $dragonPrint->id, 'quantity' => 4, 'zone' => 'main']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.default_card_id']);

        $this->assertSame(0, Deck::query()->count());
    }

    #[Test]
    public function confirm_accepts_only_the_zones_the_import_writes(): void
    {
        $user = $this->user();
        $bolt = $this->oracle('Lightning Bolt');
        $print = $this->printing($bolt, $this->set('lea', '1993-08-05'), '161');
        $row = fn (string $zone): array => ['oracle_card_id' => $bolt->id, 'default_card_id' => $print->id, 'quantity' => 1, 'zone' => $zone];

        foreach (['maybe', 'command'] as $zone) {
            $this->actingAs($user)->postJson('/decks/import-list', ['format' => 'legacy', 'deck_name' => 'X', 'rows' => [$row($zone)]])
                ->assertUnprocessable()->assertJsonValidationErrors(['rows.0.zone']);
        }
        $walker = $this->oracle('Chandra, Fire Artisan', 'R', [['type_line' => 'Legendary Planeswalker — Chandra', 'loyalty' => '4']]);
        $this->actingAs($user)->postJson('/decks/import-list', [
            'format' => 'oathbreaker', 'deck_name' => 'X', 'commander_id' => $walker->id, 'signature_spell_id' => $bolt->id,
            'rows' => [$row('companion')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.zone']);

        $this->assertSame(0, Deck::query()->count());
    }

    #[Test]
    public function the_create_form_still_validates_through_the_shared_rules(): void
    {
        $this->actingAs($this->user())
            ->post('/decks/add', ['format' => 'commander', 'deck_name' => 'X'])
            ->assertSessionHasErrors(['commander_id']);
    }
}
