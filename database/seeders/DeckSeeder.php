<?php

namespace Database\Seeders;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\User;
use App\Services\DeckService;
use Illuminate\Database\Seeder;

/**
 * Seeds two sample decks for the seed user, used to populate the deck
 * view with realistic content for local development. Both decks pin
 * every slot to a specific printing by `(set.code, collector_number)`:
 *
 *  - "Yoshi Aggro" (Commander, Boros partners) — Yoshimaru/Rograkh in
 *    the command zone with hand-picked frames; the 99 mostly chases
 *    legendary creatures + flavorful lands across LotR, Final Fantasy,
 *    Doctor Who, Kamigawa, etc.
 *  - "Lands" (Legacy) — Life from the Loam engine, with every card
 *    pinned because the deck's identity hinges on old-border frames
 *    (3ED Savannah, LEG Karakas, USG Exploration). Includes a sideboard.
 *
 * Requires the Scryfall data sync to have run first
 * (`php artisan scryfall:update`).
 *
 * Idempotent: re-running deletes the existing decks for the seed user
 * before recreating them. Cascade FKs clean up command zone rows and
 * deck_cards.
 */
class DeckSeeder extends Seeder
{
    private const YOSHI_DECK_NAME = 'Yoshi Aggro';

    private const YOSHI_DECK_FORMAT = 'commander';

    private const YOSHI_DECK_DESCRIPTION = 'Yoshimaru aggro - cast yoshi, cast legendarys, attack with Yoshi until someone dies.';

    /**
     * Command zone — Yoshimaru as the "primary" commander, Rograkh as
     * the partner. Symmetric in Commander rules, but the deck's theme
     * ("Yoshi aggro") puts Yoshimaru in the lead, so the seeded primary
     * matches the deck name and description. Each entry pins a specific
     * printing: `[oracle name (diagnostic only), set code, collector number]`.
     *
     * @var array{0: string, 1: string, 2: string}
     */
    private const YOSHI_COMMANDER_PRINTING = ['Yoshimaru, Ever Faithful', 'nec', '46'];

    /** @var array{0: string, 1: string, 2: string} */
    private const YOSHI_PARTNER_PRINTING = ['Rograkh, Son of Rohgahh', 'cmr', '575'];

    /**
     * The 99 (everything outside the command zone). Each entry pins a
     * specific printing — the deck's identity is "Boros legendaries
     * across every Universes Beyond / flavor frame", so the printing
     * matters as much as the card.
     *
     * Tuple shape: [card label (diagnostic only — Moxfield-style flavor name allowed),
     * set code (lowercase), collector number, quantity].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const YOSHI_CARDS = [
        ['Abrade', 'cmr', '659', 1],
        ['Arid Mesa', 'zen', '211', 1],
        ['Ash, Party Crasher', 'woe', '201', 1],
        ['Ashling the Pilgrim', 'lrw', '149', 1],
        ['Battlefield Forge', 'fic', '375', 1],
        ['Bessie, the Doctor\'s Roadster', 'who', '171', 1],
        ['Blackblade Reforged', 'brr', '6', 1],
        ['Blasphemous Act', 'who', '472', 1],
        ['Boros Charm', 'gtc', '148', 1],
        ['Chaos Warp', 'sta', '36', 1],
        ['Clever Concealment', 'onc', '5', 1],
        ['Clifftop Retreat', 'isd', '238', 1],
        ['Command Tower', 'cmr', '350', 1],
        ['Commander\'s Plate', 'cmr', '305', 1],
        ['Day of Destiny', 'dmc', '99', 1],
        // LotR-flavored reprint of Hammerheim — Moxfield labels it
        // "Edoras, Capital of Rohan" but the oracle row is Hammerheim.
        ['Edoras, Capital of Rohan', 'ltc', '518', 1],
        ['Eiganjo Castle', 'chk', '275', 1],
        ['Eiganjo, Seat of the Empire', 'neo', '268', 1],
        ['Eight-and-a-Half-Tails', 'chk', '8', 1],
        ['Embercleave', 'eld', '120', 1],
        ['Esper Sentinel', 'mh2', '12', 1],
        ['Flagstones of Trokair', 'tsr', '278', 1],
        ['Flaming Fist', 'clb', '18', 1],
        ['Flowering of the White Tree', 'ltr', '15', 1],
        ['Freya Crescent', 'fin', '460', 1],
        ['Galadriel\'s Dismissal', 'ltc', '500', 1],
        ['Geier Reach Sanitarium', 'emn', '203', 1],
        ['Gemstone Caverns', 'tsr', '280', 1],
        ['Generous Gift', 'cmr', '620', 1],
        ['Goro-Goro, Disciple of Ryusei', 'pneo', '145p', 1],
        ['Hope of Ghirapur', 'aer', '154', 1],
        ['Inspiring Vantage', 'kld', '246', 1],
        ['Isamaru, Hound of Konda', 'chk', '19', 1],
        ['Kari Zev, Skyship Raider', 'aer', '87', 1],
        ['Kediss, Emberclaw Familiar', 'cmr', '188', 1],
        ['Keldon Necropolis', 'inv', '325', 1],
        ['Keleth, Sunmane Familiar', 'cmr', '550', 1],
        ['Kellan, Planar Trailblazer', 'fdn', '330', 1],
        ['Kemba, Kha Enduring', 'one', '423', 1],
        ['Kor Haven', 'nem', '141', 1],
        ['Kytheon, Hero of Akros // Gideon, Battle-Forged', 'ori', '23', 1],
        ['Lae\'zel, Vlaakith\'s Champion', 'clb', '476', 1],
        ['Legion\'s Landing // Adanto, the First Fort', 'xln', '22', 1],
        ['Lost Jitte', 'big', '88', 1],
        ['Luxior, Giada\'s Gift', 'psnc', '240p', 1],
        ['Mana Confluence', 'eos', '25', 1],
        ['Merry, Esquire of Rohan', 'ltr', '215', 1],
        ['Mikokoro, Center of the Sea', 'sok', '162', 1],
        ['Minas Tirith', 'ltr', '256', 1],
        ['Mines of Moria', 'ltr', '753', 1],
        ['Mox Amber', 'brr', '35', 1],
        ['Needleverge Pathway // Pillarverge Pathway', 'znr', '288', 1],
        ['Norin the Wary', 'tsp', '171', 1],
        ['Norin, Swift Survivalist', 'dsk', '145', 1],
        ['Oswald Fiddlebender', 'afr', '28', 1],
        ['Path to Exile', 'con', '15', 1],
        ['Plains', 'one', '365', 2],
        ['Plateau', '3ed', '284', 1],
        ['Plaza of Heroes', 'pdmu', '252p', 1],
        ['Ragavan, Nimble Pilferer', 'mh2', '138', 1],
        ['Raubahn, Bull of Ala Mhigo', 'fin', '151', 1],
        ['Reyav, Master Smith', 'mul', '57', 1],
        ['Rosnakht, Heir of Rohgahh', 'dmc', '75', 1],
        ['Rugged Prairie', 'eve', '178', 1],
        ['Sacred Foundry', 'rav', '280', 1],
        ['Sarah Jane Smith', 'who', '347', 1],
        ['Shadowspear', 'pthb', '236p', 1],
        ['Shinka, the Bloodsoaked Keep', 'chk', '282', 1],
        ['Shivan Gorge', 'dmc', '232', 1],
        ['Skrelv, Defector Mite', 'one', '301', 1],
        ['Skullclamp', 'dst', '140', 1],
        ['Sokenzan, Crucible of Defiance', 'neo', '276', 1],
        ['Spear of Heliod', 'ths', '33', 1],
        ['Spectator Seating', 'cmm', '427', 1],
        ['Sram, Senior Edificer', 'mul', '6', 1],
        ['Starting Town', 'fin', '289', 1],
        ['Stone of Erech', 'ltr', '702', 1],
        ['Sunbaked Canyon', 'who', '309', 1],
        ['Sundown Pass', 'who', '310', 1],
        ['Swiftfoot Boots', 'm12', '219', 1],
        ['Swords to Plowshares', 'cmr', '627', 1],
        ['Tarrian\'s Soulcleaver', 'lci', '264', 1],
        ['Teferi\'s Protection', 'sta', '11', 1],
        ['Temur Battle Rage', 'cmr', '671', 1],
        ['Thalia, Guardian of Thraben', 'vow', '38', 1],
        ['The Grey Havens', 'ltr', '255', 1],
        ['The One Ring', 'ltr', '451', 1],
        ['The Ozolith', 'iko', '237', 1],
        ['Tuktuk the Explorer', 'roe', '169', 1],
        ['Umezawa\'s Jitte', 'bok', '163', 1],
        ['Untaidake, the Cloud Keeper', 'chk', '285', 1],
        ['Urza\'s Ruinous Blast', 'dmc', '107', 1],
        ['Vandalblast', 'cmm', '646', 1],
        ['Wrath of God', 'p07', '1', 1],
        ['Zabaz, the Glimmerwasp', 'mh2', '243', 1],
        ['Zack Fair', 'fin', '45', 1],
        ['Zurgo Bellstriker', 'dtk', '169', 1],
    ];

    private const LANDS_DECK_NAME = 'Lands';

    private const LANDS_DECK_FORMAT = 'legacy';

    private const LANDS_DECK_DESCRIPTION = 'Legacy Lands — Life from the Loam engine fueled by Wasteland / Rishadan Port denial, locking with The Tabernacle at Pendrell Vale and Maze of Ith. Closes the game with Marit Lage from Dark Depths + Thespian\'s Stage and Urza\'s Saga construct tokens.';

    /**
     * Mainboard of the Lands deck. Each entry pins a specific printing
     * by set code + collector number — the deck's identity depends on
     * the original / preferred frame for several cards.
     *
     * Tuple shape: [oracle name, set code (lowercase), collector number, quantity].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const LANDS_MAIN = [
        ['Ancient Tomb', 'tmp', '315', 2],
        ['Boseiju, Who Endures', 'pneo', '266p', 2],
        ['Crop Rotation', 'ulg', '98', 4],
        ['Dark Depths', 'dmr', '454', 2],
        ['Exploration', 'usg', '250', 4],
        ['Forest', 'ust', '216', 1],
        ['Ghost Quarter', 'dis', '173', 2],
        ['Grasping Dunes', 'akh', '244', 1],
        ['Horizon Canopy', 'fut', '177', 1],
        ['Karakas', 'leg', '303', 1],
        ['Lavaspur Boots', 'otj', '243', 1],
        ['Life from the Loam', 'rvr', '434', 4],
        ['Maze of Ith', 'drk', '117', 2],
        ['Mox Diamond', 'sth', '138', 4],
        ['Pithing Needle', 'sok', '158', 1],
        ['Riftstone Portal', 'jud', '143', 1],
        ['Savannah', '3ed', '285', 2],
        ['Shadowspear', 'thb', '236', 1],
        ['Sphere of Resistance', 'exo', '139', 4],
        ['Swords to Plowshares', 'sta', '10', 3],
        ['Sylvan Library', 'leg', '207', 2],
        ['Thespian\'s Stage', '2xm', '327', 3],
        ['Urza\'s Saga', 'mh2', '259', 4],
        ['Wasteland', 'tmp', '330', 4],
        ['Windswept Heath', 'ktk', '248', 3],
        ['Yavimaya, Cradle of Growth', 'mh2', '441', 1],
    ];

    /**
     * Sideboard of the Lands deck. Same shape as {@see LANDS_MAIN}.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const LANDS_SIDE = [
        ['Bojuka Bog', 'wwk', '132', 1],
        ['Choke', 'tmp', '219', 2],
        ['Deafening Silence', 'eld', '10', 2],
        ['Endurance', 'mh2', '157', 2],
        ['Force of Vigor', 'mh1', '164', 2],
        ['Grafdigger\'s Cage', 'dka', '149', 1],
        ['Porphyry Nodes', 'plc', '28', 1],
        ['Swords to Plowshares', 'sta', '10', 1],
        ['The Tabernacle at Pendrell Vale', 'leg', '307', 1],
        ['Torpor Orb', 'nph', '162', 2],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();

        if ($user === null) {
            $this->command->warn('No user found — run UserSeeder first. Skipping deck seed.');

            return;
        }

        $this->seedYoshiAggro($user);
        $this->seedLands($user);
    }

    /**
     * Seed the "Yoshi Aggro" Commander deck. Every slot — including
     * the command zone — is pinned to a specific printing by
     * `(set.code, collector_number)`.
     *
     * Implementation note: the command zone rows are created by
     * `DeckService::createDeck` (which auto-picks the newest printing
     * and derives `decks.colors`), then immediately overridden to point
     * at the requested printings. This keeps the colors-derivation
     * logic in one place rather than duplicating it here.
     */
    private function seedYoshiAggro(User $user): void
    {
        // Wipe the existing copy of this deck so the seeder is idempotent.
        // Cascade FKs clean up command zone rows and deck_cards.
        Deck::query()
            ->where('user_id', $user->id)
            ->where('name', self::YOSHI_DECK_NAME)
            ->delete();

        [, $commanderSet, $commanderCn] = self::YOSHI_COMMANDER_PRINTING;
        [, $partnerSet, $partnerCn] = self::YOSHI_PARTNER_PRINTING;
        $commanderPrinting = $this->resolvePrinting($commanderSet, $commanderCn);
        $partnerPrinting = $this->resolvePrinting($partnerSet, $partnerCn);

        if ($commanderPrinting === null || $partnerPrinting === null) {
            $this->command->warn(
                'Yoshimaru / Rograkh printings not found in default_cards — run `php artisan scryfall:update` first. Skipping Yoshi Aggro deck.'
            );

            return;
        }

        $deck = DeckService::createDeck($user, [
            'format' => self::YOSHI_DECK_FORMAT,
            'deck_name' => self::YOSHI_DECK_NAME,
            'deck_description' => self::YOSHI_DECK_DESCRIPTION,
            'commander_id' => $commanderPrinting->oracle_id,
            'companion_id' => $partnerPrinting->oracle_id,
        ]);

        // Override the auto-picked newest printings on the command zone
        // rows with the specific printings we asked for. Identified by
        // oracle_card_id since `(deck_id, role)` is unique but
        // `(deck_id, oracle_card_id)` works equally well and avoids
        // hard-coding a role here.
        DeckCard::query()
            ->where('deck_id', $deck->id)
            ->where('oracle_card_id', $commanderPrinting->oracle_id)
            ->update(['default_card_id' => $commanderPrinting->id]);
        DeckCard::query()
            ->where('deck_id', $deck->id)
            ->where('oracle_card_id', $partnerPrinting->oracle_id)
            ->update(['default_card_id' => $partnerPrinting->id]);

        $missing = [];
        $inserted = $this->insertPinnedPrintings($deck->id, self::YOSHI_CARDS, DeckZone::Main, $missing);

        $this->command->info("Seeded deck '".self::YOSHI_DECK_NAME."' with $inserted cards (+ 2 commanders).");

        if ($missing !== []) {
            $this->command->warn(
                'Skipped '.count($missing).' Yoshi Aggro printings not found in default_cards: '.implode(', ', $missing)
            );
        }
    }

    /**
     * Seed the "Lands" Legacy deck with mainboard + sideboard, every
     * card pinned to a specific printing by set code + collector number.
     */
    private function seedLands(User $user): void
    {
        Deck::query()
            ->where('user_id', $user->id)
            ->where('name', self::LANDS_DECK_NAME)
            ->delete();

        $deck = DeckService::createDeck($user, [
            'format' => self::LANDS_DECK_FORMAT,
            'deck_name' => self::LANDS_DECK_NAME,
            'deck_description' => self::LANDS_DECK_DESCRIPTION,
        ]);

        $missing = [];
        $insertedMain = $this->insertPinnedPrintings($deck->id, self::LANDS_MAIN, DeckZone::Main, $missing);
        $insertedSide = $this->insertPinnedPrintings($deck->id, self::LANDS_SIDE, DeckZone::Side, $missing);

        $this->command->info(
            "Seeded deck '".self::LANDS_DECK_NAME."' with $insertedMain mainboard + $insertedSide sideboard cards."
        );

        if ($missing !== []) {
            $this->command->warn(
                'Skipped '.count($missing).' Lands printings not found in default_cards: '.implode(', ', $missing)
            );
        }
    }

    /**
     * Insert deck_card rows for a list of pinned-printing tuples,
     * resolving the printing by `(set.code, collector_number)`. The
     * oracle id is read from the resolved printing, so callers do not
     * need to supply it. Missing printings are accumulated into
     * `$missing` and skipped.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $cards
     * @param  list<string>  $missing
     * @return int total quantity inserted (sum of the tuple quantities)
     */
    private function insertPinnedPrintings(string $deckId, array $cards, DeckZone $zone, array &$missing): int
    {
        $inserted = 0;

        foreach ($cards as [$name, $setCode, $collectorNumber, $quantity]) {
            $printing = $this->resolvePrinting($setCode, $collectorNumber);

            if ($printing === null) {
                $missing[] = "$name ($setCode) $collectorNumber";

                continue;
            }

            DeckCard::create([
                'deck_id' => $deckId,
                'oracle_card_id' => $printing->oracle_id,
                'default_card_id' => $printing->id,
                'zone' => $zone->value,
                'quantity' => $quantity,
            ]);
            $inserted += $quantity;
        }

        return $inserted;
    }

    /**
     * Look up a single printing by `(set.code, collector_number)`.
     * Returns null if no row matches — callers decide whether to warn
     * (per-card during the 99 walk) or hard-fail (command zone).
     */
    private function resolvePrinting(string $setCode, string $collectorNumber): ?DefaultCard
    {
        return DefaultCard::query()
            ->whereHas('set', fn ($q) => $q->where('code', $setCode))
            ->where('collector_number', $collectorNumber)
            ->first();
    }
}
