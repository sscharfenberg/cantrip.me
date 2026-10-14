<?php

namespace Database\Seeders;

use App\Enums\CardFormat;
use App\Enums\CardLanguage;
use App\Enums\ContainerType;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\User;
use App\Services\DeckService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The lane `tests/e2e/app/deck-refresh.spec.ts` writes in.
 *
 * That spec proves the deck page refreshes what an action changes without a
 * page reload — the header's total worth after a printing switch, and the
 * "Offene Karten" entry of the deck actions menu after anything that changes
 * how much of the deck the collection covers. Every one of its tests WRITES,
 * and `fullyParallel` runs them beside every reader in the suite, so they get
 * a lane nothing else touches.
 *
 * **A user of its own**, not more decks for {@see E2ESeeder::USER_ID}. The
 * readers pin that user's collection to exact numbers — nine stacks, three
 * containers, per-container totals — and its decks' availability badges
 * depend on which of its stacks are free. Stacks and deckboxes added there
 * would move all of it.
 *
 * **One deck per test**, for the same reason: two tests writing to one deck
 * would race in parallel workers. Each deck is shaped so the test's single
 * action flips what it asserts on — a fully claimed deck that an add makes
 * incomplete, an incomplete one that a claim completes.
 *
 * **Fixed deck ids**, unlike {@see E2ESeeder}'s decks. Those are built by
 * `DeckService::createDeck`, which mints the id; these are saved with their
 * id first and given a command zone afterwards through
 * `DeckService::setCommandZone`, the same call `createDeck` makes. With
 * fourteen decks split across two format folders, going through the deck list
 * would be most of each test — and the list is covered by `decks.spec.ts`.
 * The ids are mirrored in the spec.
 *
 * Every deck is `built`: a planned deck swaps the tracking-mode badges for
 * availability, and these tests are about the tracking modes.
 *
 * Two shapes of coverage are used, and the difference matters:
 *
 *  - **Mode B** counts the stacks in the deck's deckbox, BY PRINTING. Switching
 *    a card to a printing the deckbox does not hold uncovers it, which is what
 *    makes the printing-switch tests observable in the menu as well as in the
 *    worth. Each of those decks has a deckbox of its own.
 *  - **Mode C** counts the stacks claimed for each deck card. Claimed stacks
 *    are left unsorted; the stacks a test claims itself sit in
 *    {@see SPARE_BINDER_NAME}, so the assign modal can tell them apart from
 *    stacks that are already claimed or that back a mode B deckbox.
 */
class E2EDeckRefreshSeeder extends Seeder
{
    /** The account the spec signs in as. Mirrors `REFRESH_USER` in environment.ts. */
    public const USER_ID = '0198a1b2-0000-7000-8000-000000000003';

    public const USER_NAME = 'E2E Refresh';

    /** Where the stacks a test claims for itself are kept. */
    public const SPARE_BINDER_ID = '0198a1b2-0000-7000-8000-000000000301';

    public const SPARE_BINDER_NAME = 'Spare Binder';

    /** One deckbox per mode B deck, so no two of them share coverage. */
    private const CARD_PRINTING_DECKBOX_ID = '0198a1b2-0000-7000-8000-000000000302';

    private const COMMANDER_PRINTING_DECKBOX_ID = '0198a1b2-0000-7000-8000-000000000303';

    private const COMPANION_PRINTING_DECKBOX_ID = '0198a1b2-0000-7000-8000-000000000304';

    /* The decks, one per test. Mirrored in deck-refresh.spec.ts. */

    public const CARD_PRINTING_DECK_ID = '0198a1b2-0000-7000-8000-000000000401';

    public const COMMANDER_PRINTING_DECK_ID = '0198a1b2-0000-7000-8000-000000000402';

    public const COMPANION_PRINTING_DECK_ID = '0198a1b2-0000-7000-8000-000000000403';

    public const QUANTITY_DECK_ID = '0198a1b2-0000-7000-8000-000000000404';

    public const QUICK_ADD_DECK_ID = '0198a1b2-0000-7000-8000-000000000405';

    public const ADD_CARDS_DECK_ID = '0198a1b2-0000-7000-8000-000000000406';

    public const SIDEBOARD_DECK_ID = '0198a1b2-0000-7000-8000-000000000407';

    public const SPLIT_DECK_ID = '0198a1b2-0000-7000-8000-000000000408';

    public const CARD_CLAIM_DECK_ID = '0198a1b2-0000-7000-8000-000000000409';

    public const COMMANDER_CLAIM_DECK_ID = '0198a1b2-0000-7000-8000-000000000410';

    public const COMPANION_CLAIM_DECK_ID = '0198a1b2-0000-7000-8000-000000000411';

    public const COMMAND_ZONE_DECK_ID = '0198a1b2-0000-7000-8000-000000000412';

    public const COMPANION_SLOT_DECK_ID = '0198a1b2-0000-7000-8000-000000000413';

    public const ADD_ALL_DECK_ID = '0198a1b2-0000-7000-8000-000000000414';

    /** A fixed instant, so nothing a spec can read moves with the wall clock. */
    private Carbon $stamp;

    private User $user;

    public function run(): void
    {
        $this->stamp = Carbon::parse('2026-01-01 12:00:00');

        $this->user = $this->seedUser();
        $this->seedContainers();
        $this->seedPrintingDecks();
        $this->seedCardDecks();
        $this->seedCommandZoneDecks();
    }

    /** Same shape as {@see E2ESeeder}'s accounts — see there for `forceFill`. */
    private function seedUser(): User
    {
        $user = (new User)->forceFill([
            'id' => self::USER_ID,
            'name' => self::USER_NAME,
            'email' => 'e2e-refresh@cantrip.test',
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

    private function seedContainers(): void
    {
        $containers = [
            [self::SPARE_BINDER_ID, self::SPARE_BINDER_NAME, ContainerType::Binder],
            [self::CARD_PRINTING_DECKBOX_ID, 'Bolt Deckbox', ContainerType::Deckbox],
            [self::COMMANDER_PRINTING_DECKBOX_ID, 'Lurrus Deckbox', ContainerType::Deckbox],
            [self::COMPANION_PRINTING_DECKBOX_ID, 'Atraxa Lurrus Deckbox', ContainerType::Deckbox],
        ];

        foreach ($containers as $index => [$id, $name, $type]) {
            (new Container)->forceFill([
                'id' => $id,
                'user_id' => $this->user->id,
                'name' => $name,
                'type' => $type,
                'sort_order' => $index + 1,
                'created_at' => $this->stamp,
                'updated_at' => $this->stamp,
            ])->save();
        }
    }

    /**
     * Mode B, one deck per place a printing can be switched from.
     *
     * Each deckbox holds exactly the printing its deck starts on, so the deck
     * starts fully covered and switching away uncovers it. The two printings
     * on each side cost different amounts, so the switch moves the worth too:
     * Lightning Bolt LEA €409.41 / 2ED €18.21, Lurrus #226 €1.52 / #355 €3.50.
     */
    private function seedPrintingDecks(): void
    {
        $bolt = $this->printing('Lightning Bolt', 'lea');
        $deck = $this->deck(self::CARD_PRINTING_DECK_ID, 'Bolt Printing Swap', CardFormat::Legacy, 'B', self::CARD_PRINTING_DECKBOX_ID);
        $this->card($deck, $bolt);
        $this->stack($bolt->id, 1, self::CARD_PRINTING_DECKBOX_ID);

        $lurrus = $this->printing('Lurrus of the Dream-Den', 'iko', '226');
        $deck = $this->deck(self::COMMANDER_PRINTING_DECK_ID, 'Lurrus Commander Printing', CardFormat::Commander, 'B', self::COMMANDER_PRINTING_DECKBOX_ID);
        $this->commandZone($deck, $lurrus);
        $this->stack($lurrus->id, 1, self::COMMANDER_PRINTING_DECKBOX_ID);

        /* Atraxa has no price in the snapshot, so the worth is the companion's alone. */
        $atraxa = $this->printing("Atraxa, Praetors' Voice", 'c16');
        $deck = $this->deck(self::COMPANION_PRINTING_DECK_ID, 'Lurrus Companion Printing', CardFormat::Commander, 'B', self::COMPANION_PRINTING_DECKBOX_ID);
        $this->commandZone($deck, $atraxa);
        $this->card($deck, $lurrus, 1, DeckZone::Companion, DeckCardRole::Companion);
        $this->stack($atraxa->id, 1, self::COMPANION_PRINTING_DECKBOX_ID);
        $this->stack($lurrus->id, 1, self::COMPANION_PRINTING_DECKBOX_ID);
    }

    /**
     * Mode C decks whose test acts on an ordinary deck card. Legacy, because
     * moving to the sideboard and splitting a row both need a format with a
     * sideboard and more than one copy of a card.
     */
    private function seedCardDecks(): void
    {
        $bolt = $this->printing('Lightning Bolt', 'lea');

        /* Fully claimed, so one more copy, one new card or one moved copy leaves something open. */
        foreach ([
            [self::QUANTITY_DECK_ID, 'Bolt Quantity Steps'],
            [self::QUICK_ADD_DECK_ID, 'Quick Add Ritual'],
            [self::SIDEBOARD_DECK_ID, 'Bolt Sideboard Move'],
        ] as [$id, $name]) {
            $this->claim($this->card($this->deck($id, $name, CardFormat::Legacy, 'C'), $bolt));
        }

        /* Two copies under one claimed stack: splitting one off leaves the new row open. */
        $this->claim($this->card($this->deck(self::SPLIT_DECK_ID, 'Bolt Printing Split', CardFormat::Legacy, 'C'), $bolt, 2));

        /* Open, with a free copy waiting in the spare binder. */
        $this->card($this->deck(self::CARD_CLAIM_DECK_ID, 'Bolt Claim', CardFormat::Legacy, 'C'), $bolt);
        $this->stack($bolt->id, 1, self::SPARE_BINDER_ID);

        /* Open, and nothing to claim — "add all" mints the copy itself. */
        $this->card($this->deck(self::ADD_ALL_DECK_ID, 'Bolt Add All', CardFormat::Legacy, 'C'), $bolt);
    }

    /**
     * Mode C Commander decks, for the command-zone menus and the two adds a
     * Commander deck makes without a zone picker. Atraxa leads all of them;
     * Lurrus is the companion, inside Atraxa's identity.
     */
    private function seedCommandZoneDecks(): void
    {
        $atraxa = $this->printing("Atraxa, Praetors' Voice", 'c16');
        $lurrus = $this->printing('Lurrus of the Dream-Den', 'iko', '226');

        /* Fully claimed: adding a card, swapping the commander or adding a companion leaves something open. */
        foreach ([
            [self::ADD_CARDS_DECK_ID, 'Atraxa Add Cards'],
            [self::COMMAND_ZONE_DECK_ID, 'Atraxa Command Zone Swap'],
            [self::COMPANION_SLOT_DECK_ID, 'Atraxa Companion Slot'],
        ] as [$id, $name]) {
            $this->claim($this->commandZone($this->deck($id, $name, CardFormat::Commander, 'C'), $atraxa));
        }

        /* The commander open, with a free copy in the spare binder. */
        $this->commandZone($this->deck(self::COMMANDER_CLAIM_DECK_ID, 'Atraxa Commander Claim', CardFormat::Commander, 'C'), $atraxa);
        $this->stack($atraxa->id, 1, self::SPARE_BINDER_ID);

        /* The commander claimed, the companion open, a free companion in the spare binder. */
        $deck = $this->deck(self::COMPANION_CLAIM_DECK_ID, 'Atraxa Companion Claim', CardFormat::Commander, 'C');
        $this->claim($this->commandZone($deck, $atraxa));
        $this->card($deck, $lurrus, 1, DeckZone::Companion, DeckCardRole::Companion);
        $this->stack($lurrus->id, 1, self::SPARE_BINDER_ID);
    }

    /** A built deck in the given tracking mode, saved under a fixed id. */
    private function deck(string $id, string $name, CardFormat $format, string $mode, ?string $containerId = null): Deck
    {
        $deck = (new Deck)->forceFill([
            'id' => $id,
            'user_id' => $this->user->id,
            'name' => $name,
            'format' => $format,
            'state' => DeckState::Built,
            'collection_mode' => $mode,
            'container_id' => $containerId,
            'created_at' => $this->stamp,
            'updated_at' => $this->stamp,
        ]);
        $deck->save();

        return $deck;
    }

    /**
     * Give a deck its commander, through the same call deck creation makes,
     * then pin the commander to `$printing` — `setCommandZone` picks the
     * newest printing, and Lurrus's two share a release date.
     */
    private function commandZone(Deck $deck, DefaultCard $printing): DeckCard
    {
        DeckService::setCommandZone($deck, $printing->oracle_id, null, null);

        $commander = $deck->deckCards()->where('role', DeckCardRole::Commander->value)->firstOrFail();
        $commander->update(['default_card_id' => $printing->id]);

        return $commander;
    }

    private function card(Deck $deck, DefaultCard $printing, int $quantity = 1, DeckZone $zone = DeckZone::Main, ?DeckCardRole $role = null): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => $quantity,
        ]);
    }

    private function stack(string $printingId, int $amount, ?string $containerId): CardStack
    {
        $stack = (new CardStack)->forceFill([
            'user_id' => $this->user->id,
            'default_card_id' => $printingId,
            'container_id' => $containerId,
            'amount' => $amount,
            'finish' => Finish::Nonfoil,
            'language' => CardLanguage::En,
            'created_at' => $this->stamp,
            'updated_at' => $this->stamp,
        ]);
        $stack->save();

        return $stack;
    }

    /** Cover a deck card in full with an unsorted stack claimed for it. */
    private function claim(DeckCard $row): void
    {
        $stack = $this->stack($row->default_card_id, $row->quantity, null);
        $row->cardStacks()->attach($stack->id, ['created_at' => $this->stamp]);
    }

    /**
     * One printing from the snapshot, by set code and — where a set holds
     * more than one, as with Lurrus — collector number.
     */
    private function printing(string $name, string $setCode, ?string $collectorNumber = null): DefaultCard
    {
        $printing = DefaultCard::query()
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->where('default_cards.name', $name)
            ->where('sets.code', $setCode)
            ->when($collectorNumber !== null, fn ($query) => $query->where('default_cards.collector_number', $collectorNumber))
            ->select('default_cards.*')
            ->first();

        if ($printing === null) {
            throw new \RuntimeException("E2EDeckRefreshSeeder: no printing of \"$name\" from \"$setCode\" in the Scryfall snapshot.");
        }

        return $printing;
    }
}
