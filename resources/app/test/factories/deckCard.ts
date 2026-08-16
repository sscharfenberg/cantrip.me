import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";

/**
 * Counter behind the generated ids. Module-scoped, so it restarts for every
 * spec file (Vitest gives each file its own module registry) but keeps
 * counting within one.
 */
let sequence = 0;

/**
 * Restart the id counter, making the next generated id `…-1` again.
 *
 * Only needed by a spec that asserts on a generated id literally — which is
 * itself a smell. Call it from `beforeEach` if you do.
 */
export function resetSequence(): void {
    sequence = 0;
}

/**
 * Build a `DeckCardRow` for tests.
 *
 * The interface has ~20 required fields, almost none of which any given unit
 * cares about — a spec for `compareCards` is about `name` and `cmc` and
 * nothing else. This fills in the rest with the shape the controller actually
 * ships (nulls and `false`s, not placeholder truthy values) so an override is
 * the only interesting thing in a test's setup.
 *
 * The default card is a vanilla 1-mana white creature. Override `type_line`,
 * `mana_cost`, `produced_mana` and friends per test — and note that the
 * default `{W}` cost is a real white pip, so a spec measuring colour logic on
 * a *colourless* card has to say `mana_cost: ["{2}"]` explicitly.
 *
 * Three things worth knowing:
 *
 *  - `id` and `oracle_card_id` are unique within a spec file but **not stable
 *    across runs** — running one test alone produces different values than
 *    running the file. Never assert on them literally; capture the returned
 *    object instead. {@link resetSequence} exists for the rare exception.
 *  - Every call mints a *distinct* `oracle_card_id`. The app's central dual-ID
 *    case — two rows, one oracle card, different printings — needs an explicit
 *    shared `oracle_card_id` override.
 *  - `overrides` is a shallow merge, so passing `default_card` replaces the
 *    whole nested object; restate all five of its fields when you override it.
 *
 * @param overrides - Fields to replace on the generated row.
 */
export function makeDeckCard(overrides: Partial<DeckCardRow> = {}): DeckCardRow {
    sequence += 1;

    return {
        id: `deck-card-${sequence}`,
        oracle_card_id: `oracle-card-${sequence}`,
        name: "Savannah Lions",
        color_identity: "W",
        produced_mana: null,
        fetch_pattern: null,
        cmc: 1,
        type_line: "Creature — Cat",
        mana_cost: ["{W}"],
        is_basic_land: false,
        is_unlimited: false,
        is_illegal: false,
        is_game_changer: false,
        is_mld: false,
        zone: "main",
        quantity: 1,
        category_id: null,
        collection_status: null,
        collection_implicit_status: null,
        default_card: {
            id: null,
            name: null,
            card_image_0: null,
            card_image_1: null,
            set: null
        },
        ...overrides
    };
}

/**
 * Build a `DeckCommander` for tests.
 *
 * Command-zone rows are ordinary `deck_cards` rows with a `role` — see the
 * dual-ID notes in `CLAUDE.md` — but the page ships them under their own,
 * narrower shape, so they get their own factory. The default is a two-mana
 * legend that produces no mana.
 *
 * @param overrides - Fields to replace on the generated commander.
 */
export function makeCommander(overrides: Partial<DeckCommander> = {}): DeckCommander {
    sequence += 1;

    return {
        deck_card_id: `deck-card-${sequence}`,
        oracle_card_id: `oracle-card-${sequence}`,
        name: "Isamaru, Hound of Konda",
        color_identity: "W",
        produced_mana: null,
        cmc: 1,
        type_line: "Legendary Creature — Dog",
        mana_cost: ["{W}"],
        is_partner: false,
        is_illegal: false,
        collection_status: null,
        collection_implicit_status: null,
        default_card: { id: `default-card-${sequence}`, card_image_0: null, card_image_1: null },
        ...overrides
    };
}

/**
 * Build a `DeckCompanion` for tests. Defaults to Lurrus, the cheapest of the
 * ten — pick a different `name` when the spec is about a specific companion's
 * deck-building restriction.
 *
 * @param overrides - Fields to replace on the generated companion.
 */
export function makeCompanion(overrides: Partial<DeckCompanion> = {}): DeckCompanion {
    sequence += 1;

    return {
        deck_card_id: `deck-card-${sequence}`,
        oracle_card_id: `oracle-card-${sequence}`,
        name: "Lurrus of the Dream-Den",
        color_identity: "WB",
        produced_mana: null,
        cmc: 3,
        type_line: "Legendary Creature — Cat Nightmare",
        mana_cost: ["{1}{W}{B}"],
        collection_status: null,
        collection_implicit_status: null,
        default_card: { id: `default-card-${sequence}`, card_image_0: null, card_image_1: null },
        ...overrides
    };
}

/**
 * Build a user-defined deck category.
 *
 * @param name - Display name, also used to derive a readable id.
 * @param overrides - Fields to replace on the generated category.
 */
export function makeCategory(name: string, overrides: Partial<DeckCategoryRow> = {}): DeckCategoryRow {
    return { id: `category-${name.toLowerCase().replace(/\s+/g, "-")}`, name, ...overrides };
}
