<?php

namespace Database\Seeders;

use App\Enums\CardFormat;
use App\Enums\CardLanguage;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\User;
use App\Services\DeckService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The lane `tests/e2e/app/deck-copies.spec.ts` writes in: finish, language
 * and proxy of a deck card's copies ("Edit copies", and the stacks "Add all
 * to collection" mints from the plan).
 *
 * **A user of its own**, for the reason {@see E2EDeckRefreshSeeder} gives:
 * every test here writes — re-plans a card, mints stacks — and the readers
 * pin the first account's collection to exact numbers. The collection page
 * of this account is read back by the spec too, so nothing else may put
 * stacks into it.
 *
 * **One deck per test, fixed ids**, mirrored in the spec.
 *
 * The cards are picked for their prices in the snapshot, far apart between
 * finishes so a copy priced by the wrong finish moves the worth visibly:
 * Rhystic Study (PCY) €34.06 nonfoil / €204.71 foil, Krenko (M13) €1.43 /
 * €3.09, and Atraxa (C16), which exists only in foil, at €18.40.
 */
class E2EDeckCopiesSeeder extends Seeder
{
    /** The account the spec signs in as. Mirrors `COPIES_USER` in environment.ts. */
    public const USER_ID = '0198a1b2-0000-7000-8000-000000000004';

    public const USER_NAME = 'E2E Copies';

    /* The decks, one per test. Mirrored in deck-copies.spec.ts. */

    /** Four Rhystic Study planned nonfoil — re-planning one foil splits it off. */
    public const PLAN_DECK_ID = '0198a1b2-0000-7000-8000-000000000501';

    /** One Krenko planned foil and German, nothing in the collection — "add all" mints it so. */
    public const ADD_ALL_DECK_ID = '0198a1b2-0000-7000-8000-000000000502';

    /** Atraxa from C16, which exists only in foil, as commander. */
    public const COMMANDER_DECK_ID = '0198a1b2-0000-7000-8000-000000000503';

    /** A fixed instant, so nothing a spec can read moves with the wall clock. */
    private Carbon $stamp;

    private User $user;

    public function run(): void
    {
        $this->stamp = Carbon::parse('2026-01-01 12:00:00');

        $this->user = $this->seedUser();

        $this->card(
            $this->deck(self::PLAN_DECK_ID, 'Rhystic Plan', CardFormat::Legacy),
            $this->printing('Rhystic Study', 'pcy'),
            4,
        );

        $this->card(
            $this->deck(self::ADD_ALL_DECK_ID, 'Krenko Add All', CardFormat::Legacy),
            $this->printing('Krenko, Mob Boss', 'm13'),
            1,
            ['finish' => Finish::Foil, 'language' => CardLanguage::De],
        );

        $atraxa = $this->printing("Atraxa, Praetors' Voice", 'c16');
        DeckService::setCommandZone($this->deck(self::COMMANDER_DECK_ID, 'Atraxa Foil', CardFormat::Commander), $atraxa->oracle_id, null, null);
    }

    /** Same shape as {@see E2ESeeder}'s accounts — see there for `forceFill`. */
    private function seedUser(): User
    {
        $user = (new User)->forceFill([
            'id' => self::USER_ID,
            'name' => self::USER_NAME,
            'email' => 'e2e-copies@cantrip.test',
            'locale' => 'de',
            'currency' => 'eur',
            'deck_view_default' => 'text',
            'deck_sort_default' => 'mana',
            'collection_integration_enabled' => true,
            'email_verified_at' => $this->stamp,
            'password' => E2ESeeder::USER_PASSWORD,
            'created_at' => $this->stamp,
            'updated_at' => $this->stamp,
        ]);
        $user->save();

        return $user;
    }

    /** A built mode C deck, saved under a fixed id. */
    private function deck(string $id, string $name, CardFormat $format): Deck
    {
        $deck = (new Deck)->forceFill([
            'id' => $id,
            'user_id' => $this->user->id,
            'name' => $name,
            'format' => $format,
            'state' => DeckState::Built,
            'collection_mode' => 'C',
            'created_at' => $this->stamp,
            'updated_at' => $this->stamp,
        ]);
        $deck->save();

        return $deck;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function card(Deck $deck, DefaultCard $printing, int $quantity, array $plan = []): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
            ...$plan,
        ]);
    }

    /** One printing from the snapshot, by set code. */
    private function printing(string $name, string $setCode): DefaultCard
    {
        $printing = DefaultCard::query()
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->where('default_cards.name', $name)
            ->where('sets.code', $setCode)
            ->select('default_cards.*')
            ->first();

        if ($printing === null) {
            throw new \RuntimeException("E2EDeckCopiesSeeder: no printing of \"$name\" from \"$setCode\" in the Scryfall snapshot.");
        }

        return $printing;
    }
}
