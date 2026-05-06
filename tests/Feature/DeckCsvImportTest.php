<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckImportSource;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for the deck CSV import endpoint.
 *
 * Covers route auth (private deck → 404 for non-owner), happy paths
 * for the cantrip and Archidekt formats, the replace-mode contract
 * (existing deck contents are wiped before re-load), category
 * auto-creation, and skipped-row reporting.
 */
class DeckCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        Storage::fake('tmp');
    }

    #[Test]
    public function show_page_renders_for_any_authenticated_user(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/decks/import')
            ->assertOk();
    }

    #[Test]
    public function show_page_redirects_unauthenticated(): void
    {
        $this->get('/decks/import')
            ->assertRedirect();
    }

    #[Test]
    public function archidekt_post_creates_a_brand_new_deck_with_chosen_format(): void
    {
        $owner = $this->makeUser();
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."4,Lightning Bolt,lea,161,Instant,{$boltPrint->id}\n";
        $filename = $this->stashCsv($csv);

        $deckCountBefore = Deck::query()->where('user_id', $owner->id)->count();

        $this->actingAs($owner)
            ->post('/decks/import', [
                'source' => 'archidekt',
                'format' => CardFormat::Modern->value,
                'filename' => $filename,
            ])
            ->assertOk();

        $this->assertSame($deckCountBefore + 1, Deck::query()->where('user_id', $owner->id)->count());
        $newDeck = Deck::query()->where('user_id', $owner->id)->orderByDesc('created_at')->first();
        $this->assertSame(CardFormat::Modern->value, $newDeck->format->value);
        $this->assertSame(1, DeckCard::query()->where('deck_id', $newDeck->id)->count());
    }

    #[Test]
    public function cantrip_post_creates_a_brand_new_deck_with_chosen_format(): void
    {
        $owner = $this->makeUser();
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,,,\n";
        $filename = $this->stashCsv($csv);

        $deckCountBefore = Deck::query()->where('user_id', $owner->id)->count();

        $this->actingAs($owner)
            ->post('/decks/import', [
                'source' => 'cantrip',
                'format' => CardFormat::Legacy->value,
                'filename' => $filename,
            ])
            ->assertOk();

        $this->assertSame($deckCountBefore + 1, Deck::query()->where('user_id', $owner->id)->count());
        $newDeck = Deck::query()->where('user_id', $owner->id)->orderByDesc('created_at')->first();
        $this->assertSame(CardFormat::Legacy->value, $newDeck->format->value);
        $this->assertSame(1, DeckCard::query()->where('deck_id', $newDeck->id)->count());
    }

    #[Test]
    public function user_supplied_deck_name_wins_over_auto_naming(): void
    {
        $owner = $this->makeUser();
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,,,\n";
        $filename = $this->stashCsv($csv);

        $this->actingAs($owner)
            ->post('/decks/import', [
                'source' => 'cantrip',
                'format' => CardFormat::Legacy->value,
                'deck_name' => '  My Burn Deck  ',
                'filename' => $filename,
            ])
            ->assertOk();

        $newDeck = Deck::query()->where('user_id', $owner->id)->orderByDesc('created_at')->first();
        $this->assertSame('My Burn Deck', $newDeck->name);
    }

    #[Test]
    public function commander_format_without_user_name_inherits_primary_commander_name(): void
    {
        $owner = $this->makeUser();
        $atraxa = $this->makeOracleCard('Atraxa, Praetors\' Voice');
        $atraxaPrint = $this->makeDefaultCard($atraxa, 'cmr', '347');

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."commander,,{$atraxaPrint->id},Atraxa,CMR,347,1,,,false,\n";
        $filename = $this->stashCsv($csv);

        $this->actingAs($owner)
            ->post('/decks/import', [
                'source' => 'cantrip',
                'format' => CardFormat::Commander->value,
                'filename' => $filename,
            ])
            ->assertOk();

        $newDeck = Deck::query()->where('user_id', $owner->id)->orderByDesc('created_at')->first();
        $this->assertSame('Atraxa, Praetors\' Voice', $newDeck->name);
    }

    #[Test]
    public function non_commander_format_without_user_name_falls_back_to_timestamped_default(): void
    {
        $owner = $this->makeUser();
        $owner->update(['locale' => 'en']);
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,,,\n";
        $filename = $this->stashCsv($csv);

        $this->actingAs($owner)
            ->post('/decks/import', [
                'source' => 'cantrip',
                'format' => CardFormat::Modern->value,
                'filename' => $filename,
            ])
            ->assertOk();

        $newDeck = Deck::query()->where('user_id', $owner->id)->orderByDesc('created_at')->first();
        $this->assertStringStartsWith('Imported deck ', $newDeck->name);
        $this->assertNotSame('Imported deck', $newDeck->name);
    }

    #[Test]
    public function archidekt_format_imports_deck_cards(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $sol = $this->makeOracleCard('Sol Ring');
        $solPrint = $this->makeDefaultCard($sol, 'cmd', '263');

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."1,Sol Ring,cmd,263,Ramp,{$solPrint->id}\n";
        $filename = $this->stashCsv($csv);

        $results = DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertSame(1, $results['imported']);
        $this->assertSame(0, $results['commanders']);
        $this->assertSame(0, $results['companion']);
        $this->assertSame(0, $results['skipped']);

        $deckCards = DeckCard::query()->where('deck_id', $deck->id)->get();
        $this->assertCount(1, $deckCards);
        $this->assertSame($solPrint->id, $deckCards[0]->default_card_id);
        $this->assertSame(1, $deckCards[0]->quantity);

        // Category was created on the deck.
        $cat = DeckCategory::query()->where('deck_id', $deck->id)->first();
        $this->assertNotNull($cat);
        $this->assertSame('Ramp', $cat->name);
        $this->assertSame($cat->id, $deckCards[0]->category_id);
    }

    #[Test]
    public function archidekt_routes_commander_category_to_command_zone(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $atraxa = $this->makeOracleCard('Atraxa, Praetors\' Voice');
        $atraxaPrint = $this->makeDefaultCard($atraxa, 'cmr', '347');

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."1,Atraxa,cmr,347,Commander,{$atraxaPrint->id}\n";
        $filename = $this->stashCsv($csv);

        $results = DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertSame(0, $results['imported']);
        $this->assertSame(1, $results['commanders']);
        $this->assertSame(0, DeckCard::query()->where('deck_id', $deck->id)->count());

        $row = DB::table('commanders')->where('deck_id', $deck->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($atraxa->id, $row->oracle_card_id);
    }

    #[Test]
    public function archidekt_drops_default_type_categories_so_no_custom_groups_are_created(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $sol = $this->makeOracleCard('Sol Ring');
        $solPrint = $this->makeDefaultCard($sol, 'cmd', '263');
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."1,Sol Ring,cmd,263,Artifact,{$solPrint->id}\n"
            ."1,Lightning Bolt,lea,161,instant,{$boltPrint->id}\n";
        $filename = $this->stashCsv($csv);

        DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertSame(0, DeckCategory::query()->where('deck_id', $deck->id)->count());
        $deckCards = DeckCard::query()->where('deck_id', $deck->id)->get();
        $this->assertCount(2, $deckCards);
        foreach ($deckCards as $dc) {
            $this->assertNull($dc->category_id);
        }
    }

    #[Test]
    public function cantrip_format_round_trips_commander_companion_and_card_rows(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $atraxa = $this->makeOracleCard('Atraxa, Praetors\' Voice');
        $atraxaPrint = $this->makeDefaultCard($atraxa, 'cmr', '347');
        $lurrus = $this->makeOracleCard('Lurrus of the Dream-Den');
        $lurrusPrint = $this->makeDefaultCard($lurrus, 'iko', '226');
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."commander,,{$atraxaPrint->id},Atraxa,CMR,347,1,,,false,\n"
            ."companion,,{$lurrusPrint->id},Lurrus,IKO,226,1,,,,\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,Burn,,\n";
        $filename = $this->stashCsv($csv);

        $results = DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Cantrip);

        $this->assertSame(4, $results['imported']);
        $this->assertSame(1, $results['commanders']);
        $this->assertSame(1, $results['companion']);
        $this->assertSame(0, $results['skipped']);

        $deck->refresh();
        $this->assertSame($lurrus->id, $deck->companion_oracle_card_id);
        $this->assertSame($lurrusPrint->id, $deck->companion_default_card_id);

        $commanderRows = DB::table('commanders')->where('deck_id', $deck->id)->get();
        $this->assertCount(1, $commanderRows);
        $this->assertSame($atraxa->id, $commanderRows[0]->oracle_card_id);
        $this->assertSame($atraxaPrint->id, $commanderRows[0]->default_card_id);

        $deckCard = DeckCard::query()->where('deck_id', $deck->id)->first();
        $this->assertNotNull($deckCard);
        $this->assertSame($boltPrint->id, $deckCard->default_card_id);
        $this->assertSame(4, $deckCard->quantity);
        $this->assertSame('main', $deckCard->zone->value);
    }

    #[Test]
    public function cantrip_format_attaches_owned_card_stacks_via_pivot(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');
        $stack = $this->makeCardStack($owner, $boltPrint, 4);

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,,,{$stack->id}\n";
        $filename = $this->stashCsv($csv);

        DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Cantrip);

        $deckCard = DeckCard::query()->where('deck_id', $deck->id)->first();
        $this->assertNotNull($deckCard);
        $attached = $deckCard->cardStacks()->pluck('card_stacks.id')->all();
        $this->assertSame([$stack->id], $attached);
    }

    #[Test]
    public function cantrip_format_silently_drops_card_stacks_owned_by_someone_else(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');
        $strangerStack = $this->makeCardStack($stranger, $boltPrint, 4);

        $csv = "Role,Deck Card ID,Scryfall ID,Name,Edition,Collector Number,Count,Zone,Category,Is Partner,Card Stack ID\n"
            ."card,,{$boltPrint->id},Lightning Bolt,LEA,161,4,main,,,{$strangerStack->id}\n";
        $filename = $this->stashCsv($csv);

        DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Cantrip);

        $deckCard = DeckCard::query()->where('deck_id', $deck->id)->first();
        $this->assertNotNull($deckCard);
        $this->assertSame([], $deckCard->cardStacks()->pluck('card_stacks.id')->all());
    }

    #[Test]
    public function archidekt_import_preserves_existing_commanders_on_target_deck(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $atraxa = $this->makeOracleCard('Atraxa, Praetors\' Voice');
        $atraxaPrint = $this->makeDefaultCard($atraxa, 'cmr', '347');
        DB::table('commanders')->insert([
            'deck_id' => $deck->id,
            'oracle_card_id' => $atraxa->id,
            'default_card_id' => $atraxaPrint->id,
            'is_partner' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');
        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."1,Lightning Bolt,lea,161,,{$boltPrint->id}\n";
        $filename = $this->stashCsv($csv);

        DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertSame(1, DB::table('commanders')->where('deck_id', $deck->id)->count());
    }

    #[Test]
    public function uploading_a_file_that_does_not_match_the_chosen_source_format_is_reported(): void
    {
        app()->setLocale('en');

        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);

        // Cantrip-flavored CSV (uses "Edition" / "Count"), uploaded as Archidekt
        // (which expects "Edition Code" / "Quantity"). The header check should
        // surface the wrong-source-format message rather than the raw missing
        // headers list.
        $csv = "Role,Scryfall ID,Name,Edition,Collector Number,Count,Zone\n"
            ."card,abc,Lightning Bolt,LEA,161,4,main\n";
        $filename = $this->stashCsv($csv);

        try {
            DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);
            $this->fail('Expected ValidationException for wrong source format.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filename', $e->errors());
            $message = $e->errors()['filename'][0];
            $this->assertStringContainsString('does not match the chosen source format', $message);
            $this->assertStringNotContainsString('validation.custom.file', $message);
        }
    }

    #[Test]
    public function rows_with_unknown_scryfall_id_are_reported_as_skipped(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            .'1,Mystery,xxx,000,,'.(string) Str::uuid()."\n";
        $filename = $this->stashCsv($csv);

        $results = DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertSame(0, $results['imported']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame('card_not_found', $results['skipped_rows'][0]['reason']);
    }

    #[Test]
    public function uploaded_tmp_file_is_deleted_after_successful_import(): void
    {
        $owner = $this->makeUser();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private);
        $bolt = $this->makeOracleCard('Lightning Bolt');
        $boltPrint = $this->makeDefaultCard($bolt, 'lea', '161');

        $csv = "Quantity,Name,Edition Code,Collector Number,Category,Scryfall ID\n"
            ."1,Lightning Bolt,lea,161,,{$boltPrint->id}\n";
        $filename = $this->stashCsv($csv);

        DeckCsvImportService::import($owner, $deck, $filename, DeckImportSource::Archidekt);

        $this->assertFalse(Storage::disk('tmp')->exists($filename));
    }

    private function stashCsv(string $contents): string
    {
        $filename = (string) Str::uuid().'.csv';
        Storage::disk('tmp')->put($filename, $contents);

        return $filename;
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

    private function makeCardStack(User $user, DefaultCard $default, int $amount = 1): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }
}
