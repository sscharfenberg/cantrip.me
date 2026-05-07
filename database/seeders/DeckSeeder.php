<?php

namespace Database\Seeders;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Models\User;
use App\Services\DeckService;
use Illuminate\Database\Seeder;

/**
 * Seeds a sample Commander deck ("Yoshi Aggro" — Boros partners) for the
 * seed user. Used to populate the deck view with realistic content for
 * local development.
 *
 * Requires the Scryfall data sync to have run first (`php artisan
 * scryfall:update`) — every card is looked up by exact oracle name and
 * silently skipped with a warning if not present in oracle_cards.
 *
 * Idempotent: re-running deletes the existing "Yoshi Aggro" deck for the
 * seed user before recreating it. Cascade FKs clean up the commanders
 * pivot and deck_cards rows.
 */
class DeckSeeder extends Seeder
{
    private const DECK_NAME = 'Yoshi Aggro';

    private const DECK_FORMAT = 'commander';

    private const DECK_DESCRIPTION = 'Yoshimaru aggro - cast yoshi, cast legendarys, attack with Yoshi until someone dies.';

    /**
     * Command zone — Yoshimaru as the "primary" commander, Rograkh as
     * the partner. Symmetric in Commander rules, but the deck's theme
     * ("Yoshi aggro") puts Yoshimaru in the lead, so the seeded primary
     * matches the deck name and description.
     */
    private const COMMANDER_NAME = 'Yoshimaru, Ever Faithful';

    private const PARTNER_NAME = 'Rograkh, Son of Rohgahh';

    /**
     * The 99 (everything outside the command zone). Keyed by exact
     * oracle name, value = quantity.
     *
     * @var array<string, int>
     */
    private const CARDS = [
        'Abrade' => 1,
        'Arid Mesa' => 1,
        'Ash, Party Crasher' => 1,
        'Ashling the Pilgrim' => 1,
        'Battlefield Forge' => 1,
        'Bessie, the Doctor\'s Roadster' => 1,
        'Blackblade Reforged' => 1,
        'Blasphemous Act' => 1,
        'Boros Charm' => 1,
        'Chaos Warp' => 1,
        'Clever Concealment' => 1,
        'Clifftop Retreat' => 1,
        'Command Tower' => 1,
        'Commander\'s Plate' => 1,
        'Day of Destiny' => 1,
        'Hammerheim' => 1,
        'Eiganjo Castle' => 1,
        'Eiganjo, Seat of the Empire' => 1,
        'Eight-and-a-Half-Tails' => 1,
        'Embercleave' => 1,
        'Esper Sentinel' => 1,
        'Flagstones of Trokair' => 1,
        'Flaming Fist' => 1,
        'Flowering of the White Tree' => 1,
        'Freya Crescent' => 1,
        'Galadriel\'s Dismissal' => 1,
        'Geier Reach Sanitarium' => 1,
        'Generous Gift' => 1,
        'Goro-Goro, Disciple of Ryusei' => 1,
        'Hope of Ghirapur' => 1,
        'Inspiring Vantage' => 1,
        'Isamaru, Hound of Konda' => 1,
        'Kari Zev, Skyship Raider' => 1,
        'Kediss, Emberclaw Familiar' => 1,
        'Keldon Necropolis' => 1,
        'Keleth, Sunmane Familiar' => 1,
        'Kellan, Planar Trailblazer' => 1,
        'Kemba, Kha Enduring' => 1,
        'Kor Haven' => 1,
        'Kytheon, Hero of Akros // Gideon, Battle-Forged' => 1,
        'Lae\'zel, Vlaakith\'s Champion' => 1,
        'Legion\'s Landing // Adanto, the First Fort' => 1,
        'Lost Jitte' => 1,
        'Luxior, Giada\'s Gift' => 1,
        'Merry, Esquire of Rohan' => 1,
        'Mikokoro, Center of the Sea' => 1,
        'Minas Tirith' => 1,
        'Mines of Moria' => 1,
        'Mountain' => 1,
        'Mox Amber' => 1,
        'Needleverge Pathway // Pillarverge Pathway' => 1,
        'Norin the Wary' => 1,
        'Norin, Swift Survivalist' => 1,
        'Oswald Fiddlebender' => 1,
        'Path to Exile' => 1,
        'Plains' => 4,
        'Plateau' => 1,
        'Plaza of Heroes' => 1,
        'Ragavan, Nimble Pilferer' => 1,
        'Raubahn, Bull of Ala Mhigo' => 1,
        'Reyav, Master Smith' => 1,
        'Rosnakht, Heir of Rohgahh' => 1,
        'Rugged Prairie' => 1,
        'Sacred Foundry' => 1,
        'Sarah Jane Smith' => 1,
        'Shadowspear' => 1,
        'Shinka, the Bloodsoaked Keep' => 1,
        'Shivan Gorge' => 1,
        'Skrelv, Defector Mite' => 1,
        'Skullclamp' => 1,
        'Sokenzan, Crucible of Defiance' => 1,
        'Spear of Heliod' => 1,
        'Spectator Seating' => 1,
        'Sram, Senior Edificer' => 1,
        'Stone of Erech' => 1,
        'Sunbaked Canyon' => 1,
        'Sundown Pass' => 1,
        'Swiftfoot Boots' => 1,
        'Swords to Plowshares' => 1,
        'Tarrian\'s Soulcleaver' => 1,
        'Teferi\'s Protection' => 1,
        'Temur Battle Rage' => 1,
        'Thalia, Guardian of Thraben' => 1,
        'The Grey Havens' => 1,
        'The One Ring' => 1,
        'The Ozolith' => 1,
        'Tuktuk the Explorer' => 1,
        'Umezawa\'s Jitte' => 1,
        'Untaidake, the Cloud Keeper' => 1,
        'Urza\'s Ruinous Blast' => 1,
        'Vandalblast' => 1,
        'Wrath of God' => 1,
        'Zabaz, the Glimmerwasp' => 1,
        'Zack Fair' => 1,
        'Zurgo Bellstriker' => 1,
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

        // Wipe the existing copy of this deck so the seeder is idempotent.
        // Cascade FKs clean up commanders pivot rows and deck_cards.
        Deck::query()
            ->where('user_id', $user->id)
            ->where('name', self::DECK_NAME)
            ->delete();

        $commander = OracleCard::query()->where('name', self::COMMANDER_NAME)->first();
        $partner = OracleCard::query()->where('name', self::PARTNER_NAME)->first();

        if ($commander === null || $partner === null) {
            $this->command->warn(
                'Commander cards not found in oracle_cards — run `php artisan scryfall:update` first. Skipping deck seed.'
            );

            return;
        }

        $deck = DeckService::createDeck($user, [
            'format' => self::DECK_FORMAT,
            'deck_name' => self::DECK_NAME,
            'deck_description' => self::DECK_DESCRIPTION,
            'commander_id' => $commander->id,
            'companion_id' => $partner->id,
        ]);

        $missing = [];
        $inserted = 0;
        foreach (self::CARDS as $name => $quantity) {
            $oracle = OracleCard::query()->where('name', $name)->first();
            if ($oracle === null) {
                $missing[] = $name;

                continue;
            }

            $printing = DeckService::newestDefaultCard($oracle->id);
            if ($printing === null) {
                $missing[] = $name.' (no printing)';

                continue;
            }

            DeckCard::create([
                'deck_id' => $deck->id,
                'oracle_card_id' => $oracle->id,
                'default_card_id' => $printing->id,
                'quantity' => $quantity,
            ]);
            // Sum quantity, not row count — basic-land rows carry quantities
            // larger than 1 and they should count as their full pile size in
            // the final tally rather than as a single inserted row.
            $inserted += $quantity;
        }

        $this->command->info("Seeded deck '".self::DECK_NAME."' with $inserted cards (+ 2 commanders).");

        if ($missing !== []) {
            $this->command->warn('Skipped '.count($missing).' cards not found in oracle_cards: '.implode(', ', $missing));
        }
    }
}
