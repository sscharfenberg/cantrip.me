<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An id in a request that is no uuid is a validation error, and never
 * reaches a query.
 *
 * Postgres types its uuid columns and rejects a malformed value with
 * SQLSTATE 22P02 — the user gets a 500 instead of a 422. SQLite, which this
 * suite runs on, compares the value like any other text and finds nothing,
 * so a status code alone would pass with or without the fix. Each case
 * therefore records every query and fails when the malformed id is one of
 * its bindings — exactly what Postgres refuses.
 *
 * The cases cover the three ways an id used to get through: an `exists`
 * rule without `uuid` in front of it, an after-hook or closure that queries
 * whatever string it is given (they run even when a rule already failed),
 * and a query parameter nothing validated.
 */
class MalformedUuidInputTest extends TestCase
{
    use RefreshDatabase;

    private const MALFORMED = 'not-a-uuid';

    private User $user;

    private Deck $deck;

    private DeckCard $deckCard;

    private OracleCard $oracle;

    private OracleCard $commander;

    private DefaultCard $printing;

    private Container $container;

    private CardStack $stack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['collection_integration_enabled' => true]);
        $this->oracle = $this->oracleCard('Lightning Bolt', 'R');
        $this->commander = $this->oracleCard('Atraxa, Praetors\' Voice', 'WUBG');
        $companion = $this->oracleCard('Lurrus of the Dream-Den', 'WB');
        $this->printing = $this->printingOf($this->oracle);

        $this->container = Container::create([
            'user_id' => $this->user->id,
            'name' => 'Binder',
            'type' => 'binder',
            'sort_order' => 1,
        ]);
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name' => 'Superfriends',
            'format' => CardFormat::Commander->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
        $this->deckCard = $this->addToDeck($this->oracle, $this->printing, DeckZone::Main);
        $this->addToDeck($this->commander, $this->printingOf($this->commander), DeckZone::Command, DeckCardRole::Commander);
        $this->addToDeck($companion, $this->printingOf($companion), DeckZone::Companion, DeckCardRole::Companion);
        $this->stack = CardStack::create([
            'user_id' => $this->user->id,
            'default_card_id' => $this->printing->id,
            'container_id' => $this->container->id,
            'amount' => 1,
            'finish' => Finish::Nonfoil->value,
            'language' => 'en',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('malformedIds')]
    public function a_malformed_id_is_a_validation_error_and_never_reaches_a_query(
        string $method,
        string $uri,
        array $payload,
        string $field,
    ): void {
        $queried = [];
        DB::listen(function (QueryExecuted $query) use (&$queried): void {
            if (in_array(self::MALFORMED, $query->bindings, true)) {
                $queried[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->user)->json($method, $this->fill($uri), $this->fill($payload));

        $this->assertSame([], $queried, 'The malformed id reached a query; Postgres answers that with a 500.');
        $response->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    /**
     * `{deck}`, `{deckCard}`, `{stack}`, `{container}`, `{oracle}`,
     * `{commander}` and `{printing}` stand for the fixture's ids.
     *
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function malformedIds(): array
    {
        $bad = self::MALFORMED;
        $newDeck = ['format' => 'commander', 'deck_name' => 'New deck'];
        $editDeck = ['deck_name' => 'Superfriends', 'deck_visibility' => 'private', 'commander_id' => '{commander}'];
        $stackFields = ['amount' => 1, 'language' => 'en', 'finish' => 'nonfoil'];

        return [
            'adding a card to the collection: the printing' => [
                'POST', '/collection/add', ['default_card_id' => $bad] + $stackFields, 'default_card_id',
            ],
            'adding a card to the collection: the container' => [
                'POST', '/collection/add', ['default_card_id' => '{printing}', 'container_id' => $bad] + $stackFields, 'container_id',
            ],
            'editing a card stack: the container' => [
                'PATCH', '/collection/cardstack/{stack}', ['container_id' => $bad] + $stackFields, 'container_id',
            ],
            'moving selected stacks: a stack' => [
                'PATCH', '/collection/cardstack/move', ['card_stack_ids' => [$bad]], 'card_stack_ids.0',
            ],
            'moving selected stacks: the container' => [
                'PATCH', '/collection/cardstack/move', ['card_stack_ids' => ['{stack}'], 'container_id' => $bad], 'container_id',
            ],
            "moving a container's stacks: the target" => [
                'PATCH', '/containers/{container}/move-all', ['container_id' => $bad], 'container_id',
            ],
            'unclaiming a stack: where it goes' => [
                'DELETE', '/collection/cardstack/{stack}/claims', ['move_to' => $bad], 'move_to',
            ],
            "unclaiming a deck card's stacks: where they go" => [
                'DELETE', '/api/decks/{deck}/cards/{deckCard}/claims', ['move_to' => $bad], 'move_to',
            ],
            'creating a deck: the commander' => [
                'POST', '/decks/add', $newDeck + ['commander_id' => $bad], 'commander_id',
            ],
            'creating a deck: the companion' => [
                'POST', '/decks/add', $newDeck + ['commander_id' => '{commander}', 'companion_id' => $bad], 'companion_id',
            ],
            'creating a deck: the signature spell' => [
                'POST', '/decks/add', $newDeck + ['commander_id' => '{commander}', 'signature_spell_id' => $bad], 'signature_spell_id',
            ],
            "importing a deck list: a row's card" => [
                'POST', '/decks/import-list', [
                    'format' => 'modern', 'deck_name' => 'Burn',
                    'rows' => [['oracle_card_id' => $bad, 'default_card_id' => '{printing}', 'quantity' => 4, 'zone' => 'main']],
                ], 'rows.0.oracle_card_id',
            ],
            "importing a deck list: a row's printing" => [
                'POST', '/decks/import-list', [
                    'format' => 'modern', 'deck_name' => 'Burn',
                    'rows' => [['oracle_card_id' => '{oracle}', 'default_card_id' => $bad, 'quantity' => 4, 'zone' => 'main']],
                ], 'rows.0.default_card_id',
            ],
            'changing the command zone: the commander' => [
                'PATCH', '/api/decks/{deck}/commander', ['commander_id' => $bad], 'commander_id',
            ],
            'changing the command zone: the companion' => [
                'PATCH', '/api/decks/{deck}/commander', ['commander_id' => '{commander}', 'companion_id' => $bad], 'companion_id',
            ],
            'changing the command zone: the signature spell' => [
                'PATCH', '/api/decks/{deck}/commander', ['commander_id' => '{commander}', 'signature_spell_id' => $bad], 'signature_spell_id',
            ],
            'editing a deck: the commander' => [
                'PATCH', '/decks/{deck}', ['commander_id' => $bad] + $editDeck, 'commander_id',
            ],
            'editing a deck: the companion' => [
                'PATCH', '/decks/{deck}', $editDeck + ['companion_id' => $bad], 'companion_id',
            ],
            'editing a deck: the signature spell' => [
                'PATCH', '/decks/{deck}', $editDeck + ['signature_spell_id' => $bad], 'signature_spell_id',
            ],
            'editing a deck: the hero image' => [
                'PATCH', '/decks/{deck}', $editDeck + ['default_card_id' => $bad], 'default_card_id',
            ],
            'editing a deck: the deckbox' => [
                'PATCH', '/decks/{deck}', $editDeck + ['container_id' => $bad], 'container_id',
            ],
            'editing a deck: where freed copies go' => [
                'PATCH', '/decks/{deck}', $editDeck + ['move_to' => $bad], 'move_to',
            ],
            'adding all cards to the collection: the container' => [
                'POST', '/decks/{deck}/add-all-to-collection', ['container_id' => $bad], 'container_id',
            ],
            'claiming copies in bulk: the container' => [
                'POST', '/decks/{deck}/bulk-claim', ['container_id' => $bad], 'container_id',
            ],
            "switching a deck card's printing" => [
                'PATCH', '/api/decks/{deck}/cards/{deckCard}/printing', ['default_card_id' => $bad], 'default_card_id',
            ],
            "switching the commander's printing" => [
                'PATCH', '/api/decks/{deck}/commander/{commander}/printing', ['default_card_id' => $bad], 'default_card_id',
            ],
            'setting the companion' => [
                'PATCH', '/api/decks/{deck}/companion', ['oracle_card_id' => $bad], 'oracle_card_id',
            ],
            "switching the companion's printing" => [
                'PATCH', '/api/decks/{deck}/companion/printing', ['default_card_id' => $bad], 'default_card_id',
            ],
            'assigning a physical copy' => [
                'PATCH', '/api/decks/{deck}/cards/{deckCard}/assigned-stacks', ['card_stack_id' => $bad], 'card_stack_id',
            ],
            'splitting a deck card by printing' => [
                'PATCH', '/api/decks/{deck}/cards/{deckCard}/split', ['splits' => [
                    ['default_card_id' => $bad, 'quantity' => 1],
                    ['default_card_id' => '{printing}', 'quantity' => 1],
                ]], 'splits.0.default_card_id',
            ],
            'searching commanders: the card to leave out' => [
                'GET', "/api/commander?format=commander&q=atraxa&exclude={$bad}", [], 'exclude',
            ],
            'searching oathbreaker cards: the card to leave out' => [
                'GET', "/api/oathbreaker?format=oathbreaker&type=spell&q=bolt&exclude={$bad}", [], 'exclude',
            ],
        ];
    }

    /**
     * Replace the fixture placeholders in a URI or (recursively) a payload.
     *
     * @template T of string|array<mixed>
     *
     * @param  T  $value
     * @return T
     */
    private function fill(string|array $value): string|array
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => is_string($item) || is_array($item) ? $this->fill($item) : $item, $value);
        }

        return strtr($value, [
            '{deckCard}' => $this->deckCard->id,
            '{deck}' => $this->deck->id,
            '{stack}' => $this->stack->id,
            '{container}' => $this->container->id,
            '{oracle}' => $this->oracle->id,
            '{commander}' => $this->commander->id,
            '{printing}' => $this->printing->id,
        ]);
    }

    private function oracleCard(string $name, string $colorIdentity): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => $colorIdentity,
            'scryfall_uri' => 'https://example.com/card',
        ]);
    }

    private function printingOf(OracleCard $oracle): DefaultCard
    {
        $code = Str::lower(Str::random(3));
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => "Set {$code}",
            'code' => $code,
            'released_at' => '1993-08-05',
            'card_count' => 1,
            'set_type' => 'core',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => (string) random_int(1, 400),
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function addToDeck(OracleCard $oracle, DefaultCard $printing, DeckZone $zone, ?DeckCardRole $role = null): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $this->deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $printing->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => 1,
        ]);
    }
}
