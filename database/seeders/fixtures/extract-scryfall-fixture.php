<?php

/**
 * Regenerates `scryfall.json`, the Scryfall snapshot the Playwright suite seeds.
 *
 * RUN IT ON A HOST WITH A FULL DATASET — in practice staging, since local
 * development has no Scryfall data and the e2e container deliberately holds only
 * this fixture. It reads the live tables and prints one JSON document on stdout:
 *
 * ```bash
 * ssh cantrip
 * cd /var/www/mbos
 * php database/seeders/fixtures/extract-scryfall-fixture.php > /tmp/scryfall.json
 * # then copy it back over database/seeders/fixtures/scryfall.json and re-run `npm run e2e`
 * ```
 *
 * TO ADD A CARD, add its exact oracle name to `$wanted` with the number of
 * printings to keep, and re-run. Anything the query cannot find is reported on
 * stderr rather than silently skipped — a typo in a card name would otherwise
 * become a fixture that is quietly missing a card, and then a spec that fails
 * somewhere else entirely.
 *
 * The result is COMMITTED. This script is not run by anything automatically, and
 * nothing at test time talks to staging.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Card name => how many printings to keep.
 *
 * More than one only where a spec needs a printing PICKER to have something to
 * pick; everywhere else one printing keeps the fixture small and the assertions
 * unambiguous.
 *
 * The set is chosen so that colour identity can be asserted in BOTH directions:
 * Lightning Bolt is legal under the Boros partners and illegal under Atraxa,
 * Counterspell the other way round. See `E2ESeeder::seedDecks`.
 */
$wanted = [
    // Command zone.
    "Atraxa, Praetors' Voice" => 1,
    'Krenko, Mob Boss' => 1,
    'Yoshimaru, Ever Faithful' => 1,
    'Rograkh, Son of Rohgahh' => 1,

    // Staples, spread across all five colours plus colourless.
    'Sol Ring' => 3,
    'Lightning Bolt' => 3,
    'Counterspell' => 2,
    'Swords to Plowshares' => 1,
    'Dark Ritual' => 1,
    'Llanowar Elves' => 1,
    'Cultivate' => 1,
    'Rhystic Study' => 1,
    'Arcane Signet' => 1,
    'Command Tower' => 1,

    // Cards carrying a flag the app reasons about: `mld` drives the bracket
    // hint, `fetch_pattern` drives deck-aware fetchland resolution.
    'Armageddon' => 1,
    'Evolving Wilds' => 1,

    // A two-faced card, so `oracle_card_faces` has a row with face_index 1.
    'Delver of Secrets // Insectile Aberration' => 1,

    // Basics.
    'Plains' => 1,
    'Island' => 1,
    'Swamp' => 1,
    'Mountain' => 1,
    'Forest' => 1,
];

$oracleIds = [];
$defaultCards = [];
$missing = [];

foreach ($wanted as $name => $printings) {
    /*
     * Real cards before tokens. Several names exist as both — "Llanowar Elves"
     * has a Token Creature oracle row alongside the creature — and taking
     * whichever came back first put a token in the fixture.
     */
    $oracle = DB::table('oracle_cards')
        ->where('name', $name)
        ->orderByRaw("CASE WHEN type_line LIKE 'Token %' OR type_line LIKE '%Token Creature%' THEN 1 ELSE 0 END")
        ->first();

    if (! $oracle) {
        $missing[] = $name;

        continue;
    }

    $oracleIds[] = $oracle->id;

    /*
     * Oldest printings first, tie-broken by collector number with length
     * leading so '9' sorts before '10'. A function of the data rather than of
     * row order, so re-running this on a different host gives the same answer.
     *
     * Paper only (`digital = 0`): a digital-only printing has no price and no
     * meaningful collector number for the picker to show.
     */
    $rows = DB::table('default_cards')
        ->join('sets', 'sets.id', '=', 'default_cards.set_id')
        ->where('default_cards.oracle_id', $oracle->id)
        ->where('default_cards.digital', 0)
        ->orderBy('sets.released_at')
        ->orderByRaw('LENGTH(default_cards.collector_number)')
        ->orderBy('default_cards.collector_number')
        ->limit($printings)
        ->select('default_cards.*')
        ->get();

    foreach ($rows as $row) {
        $defaultCards[] = $row;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'NOT FOUND, and therefore missing from the fixture: '.implode(' | ', $missing).PHP_EOL);
}

$setIds = array_values(array_unique(array_column($defaultCards, 'set_id')));
$artistIds = array_values(array_filter(array_unique(array_column($defaultCards, 'artist_id'))));

/*
 * Every ORDER BY here is for the diff rather than for the app: a regenerated
 * fixture should differ from the committed one only where the data actually
 * changed, not wherever the database felt like returning rows in a new order.
 *
 * `symbols` is taken whole. It is 84 rows, it has no foreign keys, and every
 * mana cost the app renders looks its pieces up in it — a subset would mean a
 * card rendering blank pips for a symbol nobody remembered to include.
 */
echo json_encode([
    'sets' => DB::table('sets')->whereIn('id', $setIds)->orderBy('released_at')->get(),
    'artists' => DB::table('artists')->whereIn('id', $artistIds)->orderBy('name')->get(),
    'symbols' => DB::table('symbols')->orderBy('symbol')->get(),
    'oracle_cards' => DB::table('oracle_cards')->whereIn('id', $oracleIds)->orderBy('name')->get(),
    'oracle_card_faces' => DB::table('oracle_card_faces')
        ->whereIn('oracle_card_id', $oracleIds)
        ->orderBy('oracle_card_id')->orderBy('face_index')->get(),
    'legalities' => DB::table('legalities')
        ->whereIn('oracle_card_id', $oracleIds)
        ->orderBy('oracle_card_id')->orderBy('format')->get(),
    'default_cards' => collect($defaultCards)->sortBy(['name', 'collector_number'])->values(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
