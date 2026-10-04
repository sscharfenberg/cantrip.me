import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import type { DeckListCard, DeckListLine, DeckListParseResult } from "Types/deckListImport.ts";

/**
 * Fixtures for the deck list import — the parse response and its parts.
 *
 * Ids derive from the name so two calls for the same card agree, which is
 * what the copy-count and command-zone dedupe rules key on.
 */

let lineNumber = 0;

/** A resolved card, legal, four copies allowed, mono-red. */
export function makeDeckListCard(name = "Lightning Bolt", overrides: Partial<DeckListCard> = {}): DeckListCard {
    return {
        oracle_card_id: `oracle-${name}`,
        name,
        color_identity: "R",
        type_line: "Instant",
        is_legal: true,
        copy_limit: 4,
        default_card_id: `printing-${name}`,
        set_code: "lea",
        collector_number: "161",
        ...overrides
    };
}

/** A resolved line of `quantity` copies of `card` in the main deck. */
export function makeDeckListLine(
    card: DeckListCard | null = makeDeckListCard(),
    overrides: Partial<DeckListLine> = {}
): DeckListLine {
    lineNumber += 1;
    return {
        line: lineNumber,
        raw: `1 ${card?.name ?? "Unknown"}`,
        section: 0,
        zone: "main",
        zone_guessed: false,
        quantity: 1,
        name: card?.name ?? "Unknown",
        category: null,
        status: card ? "resolved" : "unresolved",
        reason: card ? null : "not_found",
        notices: [],
        card,
        availability: null,
        suggestions: [],
        ...overrides
    };
}

/** A command-zone card as the pickers return it. */
export function makeCommanderResult(
    name = "Krenko, Mob Boss",
    overrides: Partial<CommanderResult> = {}
): CommanderResult {
    return {
        id: `oracle-${name}`,
        name,
        color_identity: "R",
        companion_type: null,
        partner_with_name: null,
        faces: [{ type_line: "Legendary Creature — Goblin Warrior", mana_cost: "{2}{R}{R}" }],
        matched_translation: null,
        ...overrides
    };
}

/** A parse response for a Legacy deck — no command zone — around the given lines. */
export function makeParseResult(
    lines: DeckListLine[],
    overrides: Partial<DeckListParseResult> = {}
): DeckListParseResult {
    const sectionIndexes = [...new Set(lines.map(line => line.section))];
    return {
        sections: (sectionIndexes.length ? sectionIndexes : [0]).map(index => ({
            index,
            label: null,
            zone: "main",
            zone_guessed: false
        })),
        lines,
        dropped: 0,
        command_zone: { commander: null, partner: null, signature_spell: null },
        rules: {
            format: "legacy",
            minDeckSize: 60,
            maxDeckSize: null,
            maxSideboardSize: 15,
            maxCopies: 4,
            isSingleton: false,
            requiresCommander: false,
            maxCommanders: 0,
            enforcesColorIdentity: false,
            hasSignatureSpell: false,
            companionPlacement: "sideboard",
            usesGameChangerList: false,
            allowsCompanion: true
        },
        collection: true,
        ...overrides
    };
}

/** Commander-format rules, for `makeParseResult(lines, { rules: commanderRules() })`. */
export function commanderRules(overrides: Partial<DeckListParseResult["rules"]> = {}): DeckListParseResult["rules"] {
    return {
        ...makeParseResult([]).rules,
        format: "commander",
        minDeckSize: 100,
        maxDeckSize: 100,
        maxCopies: 1,
        isSingleton: true,
        requiresCommander: true,
        maxCommanders: 2,
        enforcesColorIdentity: true,
        companionPlacement: "outside",
        usesGameChangerList: true,
        ...overrides
    };
}
