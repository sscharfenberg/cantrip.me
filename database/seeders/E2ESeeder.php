<?php

namespace Database\Seeders;

use App\Enums\CardLanguage;
use App\Enums\ContainerType;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\User;
use App\Services\DeckService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The fixture the Playwright end-to-end suite runs against.
 *
 * **Why this is not `DatabaseSeeder`.** That one chains `DeckSeeder`, which pins
 * every slot to a specific Scryfall printing by `(set code, collector number)` —
 * so it only works on a machine that has run `php artisan scryfall:update` and
 * holds the full 475 MB dataset. A browser suite cannot assume that, and should
 * not want to: the point of a fixture is that the same rows exist every time.
 * Instead, a small snapshot of real Scryfall rows is committed alongside this
 * class and loaded from disk (see {@see seedScryfall}).
 *
 * **Everything here is fixed.** No factories, no `fake()`, no `inRandomOrder()`,
 * no `now()` in a value a spec might read. A browser test names the thing it
 * clicks, so a re-rolled fixture is a re-rolled set of selectors.
 *
 * IDs are literal UUIDs rather than generated ones for the same reason: a spec
 * (or a support helper reaching into the database directly) can address a row
 * without first looking it up. The decks are the one exception, and the constant
 * block below says why.
 *
 * **Known gap: card images 404.** The snapshot keeps the real `card_image_*` and
 * `art_crop` paths, which point into `public/card-images` — a directory this
 * machine does not have. Card art therefore renders broken. That is deliberate
 * for now: writing placeholder files there would mean writing into a directory
 * whose meaning differs per machine (on the servers it is a symlink into shared
 * storage). Nothing asserts on artwork yet; a spec that needs it should generate
 * the files from `tests/e2e/support/environment.ts` rather than commit them.
 *
 * Run by `tests/e2e/support/environment.ts` after `migrate:fresh`:
 *
 * ```bash
 * php artisan db:seed --class=Database\\Seeders\\E2ESeeder --force
 * ```
 */
class E2ESeeder extends Seeder
{
    /** The account every authenticated spec signs in as. Mirrors `SEED_USER` in environment.ts. */
    public const USER_ID = '0198a1b2-0000-7000-8000-000000000001';

    public const USER_NAME = 'E2E Tester';

    public const USER_PASSWORD = 'e2e-password';

    /**
     * The three seeded decks.
     *
     * They are not three of the same thing. Each one exists to make a different
     * question answerable, and the colour identities are chosen so that a search
     * assertion can be non-vacuous in BOTH directions — see {@see seedDecks}.
     *
     * NAMES ONLY, no fixed ids, and that is a deliberate exception to the rule
     * the class docblock states. Decks are built by `DeckService::createDeck`,
     * which mints the id and then writes command-zone `deck_cards` rows against
     * it; rewriting the primary key afterwards would mean updating a row that
     * child rows already reference. A spec reaches a deck through the deck list,
     * which is how a user reaches one too.
     */
    public const ATRAXA_DECK_NAME = 'Atraxa Superfriends';

    public const BOROS_DECK_NAME = 'Yoshi and Rograkh';

    public const BURN_DECK_NAME = 'Legacy Burn';

    /** The three seeded containers. */
    public const BINDER_ID = '0198a1b2-0000-7000-8000-000000000201';

    public const BINDER_NAME = 'Trade Binder';

    public const DECKBOX_ID = '0198a1b2-0000-7000-8000-000000000202';

    public const DECKBOX_NAME = 'Atraxa Deckbox';

    public const DISPLAY_ID = '0198a1b2-0000-7000-8000-000000000203';

    public const DISPLAY_NAME = 'Sealed Display';

    /**
     * The Scryfall snapshot, in FK order.
     *
     * The order is the whole reason this is a list rather than a loop over the
     * file's keys: `default_cards` references `sets`, `artists` and
     * `oracle_cards`, and `legalities` references `oracle_cards`. JSON object
     * order happens to match today, which is exactly the kind of thing that
     * silently stops being true.
     */
    private const SCRYFALL_TABLES = [
        'sets',
        'artists',
        'symbols',
        'oracle_cards',
        'oracle_card_faces',
        'legalities',
        'default_cards',
    ];

    /** A fixed instant, so nothing a spec can read moves with the wall clock. */
    private Carbon $stamp;

    public function run(): void
    {
        $this->stamp = Carbon::parse('2026-01-01 12:00:00');

        $user = $this->seedUser();
        $this->seedScryfall();
        $this->seedContainers($user);
        $this->seedCardStacks($user);
        $this->seedDecks($user);
    }

    /**
     * The signed-in account.
     *
     * Verified up front (`email_verified_at` set), because the verification flow
     * gets its own spec that mints its own unverified user — leaving this one
     * unverified would put a verification wall in front of every authenticated
     * spec instead.
     */
    private function seedUser(): User
    {
        /*
         * `forceFill`, because `id`, `created_at` and `updated_at` are all
         * outside `$fillable` — `create()` would silently drop them and mint a
         * random UUID and a wall-clock timestamp instead, which is the one thing
         * this seeder exists to avoid. Casts still apply, so `password` is
         * hashed on the way in.
         */
        $user = (new User)->forceFill([
            'id' => self::USER_ID,
            'name' => self::USER_NAME,
            'email' => 'e2e@cantrip.test',
            'locale' => 'de',
            'currency' => 'eur',
            'deck_view_default' => 'text',
            'deck_sort_default' => 'mana',
            'collection_integration_enabled' => true,
            'email_verified_at' => $this->stamp,
            'password' => self::USER_PASSWORD,
            'created_at' => $this->stamp,
            'updated_at' => $this->stamp,
        ]);
        $user->save();

        return $user;
    }

    /**
     * Load the committed Scryfall snapshot.
     *
     * 22 oracle cards and 27 printings, pulled from the real dataset on staging
     * — real ids, real oracle text, real prices, real `color_identity` strings —
     * because the app reasons about all of it and a hand-written approximation
     * would be a fixture that agrees with the tests rather than with Magic.
     *
     * `DB::table()->insert()` rather than the Eloquent models: the rows already
     * carry their own primary keys and timestamps, and pushing them through
     * `HasUuids` would replace the ids that the decks below then reference.
     */
    private function seedScryfall(): void
    {
        $path = database_path('seeders/fixtures/scryfall.json');
        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (self::SCRYFALL_TABLES as $table) {
            /* Chunked, because `legalities` alone is 500-odd rows and MariaDB's
             * placeholder limit is a real ceiling rather than a distant one. */
            foreach (array_chunk($fixture[$table], 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    /**
     * Three containers, one of each shape the collection UI treats differently:
     * a binder, a deckbox (which a deck can point its `container_id` at, for the
     * mode B coverage story) and a display.
     */
    private function seedContainers(User $user): void
    {
        $containers = [
            [self::BINDER_ID, self::BINDER_NAME, ContainerType::Binder, 'Karten, die weg dürfen.'],
            [self::DECKBOX_ID, self::DECKBOX_NAME, ContainerType::Deckbox, 'Die physischen Karten des Atraxa-Decks.'],
            [self::DISPLAY_ID, self::DISPLAY_NAME, ContainerType::Display, 'Ungeöffnet.'],
        ];

        foreach ($containers as $index => [$id, $name, $type, $description]) {
            (new Container)->forceFill([
                'id' => $id,
                'user_id' => $user->id,
                'name' => $name,
                'type' => $type,
                'description' => $description,
                'sort_order' => $index + 1,
                'created_at' => $this->stamp,
                'updated_at' => $this->stamp,
            ])->save();
        }
    }

    /**
     * A physical collection with fixed amounts, finishes and languages.
     *
     * Deliberately NOT a copy of the Atraxa deck list. The interesting states in
     * the deck ↔ collection integration are the ones where the two disagree, so
     * the deckbox holds some of that deck's cards and not others, and the binder
     * holds a card no deck wants. A fixture that owned everything could only
     * ever produce one badge.
     */
    private function seedCardStacks(User $user): void
    {
        $stacks = [
            // In the Atraxa deckbox — these are the cards that can read "covered".
            [self::DECKBOX_ID, 'Sol Ring', 1, Finish::Nonfoil, CardLanguage::En],
            [self::DECKBOX_ID, 'Command Tower', 1, Finish::Nonfoil, CardLanguage::En],
            [self::DECKBOX_ID, 'Swords to Plowshares', 1, Finish::Nonfoil, CardLanguage::De],
            [self::DECKBOX_ID, 'Forest', 8, Finish::Nonfoil, CardLanguage::En],

            // Owned, but sitting somewhere else — "elsewhere" rather than "covered".
            [self::BINDER_ID, 'Counterspell', 2, Finish::Nonfoil, CardLanguage::En],
            [self::BINDER_ID, 'Rhystic Study', 1, Finish::Nonfoil, CardLanguage::En],

            // Owned and wanted by no deck at all.
            [self::BINDER_ID, 'Dark Ritual', 4, Finish::Nonfoil, CardLanguage::De],
            [self::DISPLAY_ID, 'Lightning Bolt', 3, Finish::Nonfoil, CardLanguage::En],
        ];

        foreach ($stacks as [$containerId, $cardName, $amount, $finish, $language]) {
            (new CardStack)->forceFill([
                'user_id' => $user->id,
                'default_card_id' => $this->printing($cardName)->id,
                'container_id' => $containerId,
                'amount' => $amount,
                'finish' => $finish,
                'language' => $language,
                'created_at' => $this->stamp,
                'updated_at' => $this->stamp,
            ])->save();
        }
    }

    /**
     * Three decks, chosen so that colour identity can be asserted in both
     * directions.
     *
     * Atraxa's identity is WUBG and Yoshimaru+Rograkh's is RW, so **Lightning
     * Bolt is legal in one and illegal in the other, and Counterspell is the
     * other way round**. That is what makes a card-search assertion mean
     * something: a search that wrongly ignored `color_identity` would return a
     * hit in both decks, and a search that returned nothing at all would fail
     * both — neither can pass by accident.
     *
     * The third deck is Legacy, which has no command zone at all, so the
     * command-zone UI has a case where it must be absent rather than empty.
     */
    private function seedDecks(User $user): void
    {
        $atraxa = DeckService::createDeck($user, [
            'format' => 'commander',
            'deck_name' => self::ATRAXA_DECK_NAME,
            'deck_description' => 'Vier Farben, ein Plan.',
            'commander_id' => $this->oracleId("Atraxa, Praetors' Voice"),
        ]);

        $this->addCards($atraxa->id, [
            ['Sol Ring', 1, DeckZone::Main],
            ['Arcane Signet', 1, DeckZone::Main],
            ['Command Tower', 1, DeckZone::Main],
            ['Swords to Plowshares', 1, DeckZone::Main],
            ['Counterspell', 1, DeckZone::Main],
            ['Rhystic Study', 1, DeckZone::Main],
            ['Cultivate', 1, DeckZone::Main],
            ['Llanowar Elves', 1, DeckZone::Main],
            ['Plains', 4, DeckZone::Main],
            ['Island', 4, DeckZone::Main],
            ['Swamp', 4, DeckZone::Main],
            ['Forest', 4, DeckZone::Main],
            // One card parked in the maybeboard, so that zone is never empty.
            ['Delver of Secrets // Insectile Aberration', 1, DeckZone::Maybe],
        ]);

        /*
         * The partner pair. `companion_id` is the command zone's SECOND slot in
         * DeckService's vocabulary — the partner — and not the Magic keyword
         * companion, which is a separate `zone=companion` row.
         */
        $boros = DeckService::createDeck($user, [
            'format' => 'commander',
            'deck_name' => self::BOROS_DECK_NAME,
            'deck_description' => 'Zwei Partner, ein Hund.',
            'commander_id' => $this->oracleId('Yoshimaru, Ever Faithful'),
            'companion_id' => $this->oracleId('Rograkh, Son of Rohgahh'),
        ]);

        $this->addCards($boros->id, [
            ['Lightning Bolt', 1, DeckZone::Main],
            ['Swords to Plowshares', 1, DeckZone::Main],
            ['Armageddon', 1, DeckZone::Main],
            ['Sol Ring', 1, DeckZone::Main],
            ['Command Tower', 1, DeckZone::Main],
            ['Plains', 6, DeckZone::Main],
            ['Mountain', 6, DeckZone::Main],
        ]);

        $burn = DeckService::createDeck($user, [
            'format' => 'legacy',
            'deck_name' => self::BURN_DECK_NAME,
            'deck_description' => 'Ziel ist der Kopf.',
        ]);

        /* A 60-card format, so quantities above one and a real sideboard. */
        $this->addCards($burn->id, [
            ['Lightning Bolt', 4, DeckZone::Main],
            ['Mountain', 20, DeckZone::Main],
            ['Swords to Plowshares', 2, DeckZone::Side],
        ]);
    }

    /**
     * Insert `deck_cards` rows for a deck.
     *
     * @param  list<array{0: string, 1: int, 2: DeckZone}>  $cards
     */
    private function addCards(string $deckId, array $cards): void
    {
        foreach ($cards as [$name, $quantity, $zone]) {
            $printing = $this->printing($name);

            DeckCard::create([
                'deck_id' => $deckId,
                'oracle_card_id' => $printing->oracle_id,
                'default_card_id' => $printing->id,
                'zone' => $zone->value,
                'quantity' => $quantity,
            ]);
        }
    }

    /**
     * The printing a deck or stack should use for a card name.
     *
     * The OLDEST one in the snapshot, deterministically. Several cards were
     * extracted with more than one printing so that the printing picker has
     * something to pick, and "whichever row comes back first" would make the
     * fixture depend on storage order — which is precisely the kind of thing
     * that changes under you and fails a spec two milestones later.
     */
    private function printing(string $name): DefaultCard
    {
        $printing = DefaultCard::query()
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->where('default_cards.name', $name)
            ->orderBy('sets.released_at')
            /* Length first, so '9' sorts before '10' rather than after it. */
            ->orderByRaw('LENGTH(default_cards.collector_number)')
            ->orderBy('default_cards.collector_number')
            ->select('default_cards.*')
            ->first();

        if ($printing === null) {
            throw new \RuntimeException("E2ESeeder: no printing of \"$name\" in the Scryfall snapshot.");
        }

        return $printing;
    }

    /** The oracle id behind a card name, for the command-zone slots. */
    private function oracleId(string $name): string
    {
        return $this->printing($name)->oracle_id;
    }
}
