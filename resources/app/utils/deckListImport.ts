import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import type { DeckListCandidate, DeckListCard, DeckListLine, DeckListZone } from "Types/deckListImport.ts";
import type { CollectionAvailability } from "Types/deckPage.ts";

/**
 * The deck list import's review logic, as pure functions over the parse
 * response and what the user changed on the review page.
 *
 * The server ships facts (`is_legal`, `copy_limit`, `color_identity`); the
 * warnings that depend on the user's edits — exclusions, replacements, the
 * chosen command zone — are derived here, so they follow every edit without
 * a round trip.
 */

/** What the user changed about one line. */
export interface LineChoice {
    /** Left out of the import. */
    excluded: boolean;
    /** Card picked for the line instead — the fix for an unresolved line. */
    replacement: DeckListCandidate | null;
}

/** The command zone as chosen on the review page. */
export interface ChosenCommandZone {
    commander: CommanderResult | null;
    partner: CommanderResult | null;
    signatureSpell: CommanderResult | null;
}

/** A line as it will be imported. */
export interface EffectiveLine {
    line: number;
    card: DeckListCard;
    availability: CollectionAvailability | null;
    /** After the command-zone copy was taken out; 0 when the line was the command-zone card's only copy. */
    quantity: number;
    zone: DeckListZone;
    category: string | null;
    /** Copies moved out because the card is in the command zone. */
    deduped: number;
}

export type LineWarning = "not_legal" | "too_many_copies" | "color_identity";

/** The card a line imports: its replacement, its own card, or none (unresolved). */
export function candidateOf(line: DeckListLine, choice: LineChoice | undefined): DeckListCandidate | null {
    if (choice?.replacement) return choice.replacement;
    return line.card ? { card: line.card, availability: line.availability } : null;
}

/** Lines still blocking the import: unresolved, not replaced, not excluded. */
export function blockingLines(lines: DeckListLine[], choices: Record<number, LineChoice>): DeckListLine[] {
    return lines.filter(line => !choices[line.line]?.excluded && candidateOf(line, choices[line.line]) === null);
}

/**
 * The lines that will be imported, in paste order.
 *
 * A section whose zone the parser guessed takes the zone the user chose for
 * it. A card in the command zone that also appears in the main deck loses
 * one copy there — the paste listed it in the 99 as well, and importing it
 * twice would put a 101st card in a singleton deck.
 */
export function effectiveLines(
    lines: DeckListLine[],
    choices: Record<number, LineChoice>,
    sectionZones: Record<number, DeckListZone>,
    commandZone: ChosenCommandZone
): EffectiveLine[] {
    const pending = commandZoneIds(commandZone);
    const result: EffectiveLine[] = [];
    for (const line of lines) {
        const choice = choices[line.line];
        if (choice?.excluded) continue;
        const candidate = candidateOf(line, choice);
        if (candidate === null) continue;

        const zone = line.zone_guessed ? (sectionZones[line.section] ?? line.zone) : line.zone;
        let deduped = 0;
        const at = pending.indexOf(candidate.card.oracle_card_id);
        if (zone === "main" && at !== -1) {
            pending.splice(at, 1);
            deduped = 1;
        }

        result.push({
            line: line.line,
            card: candidate.card,
            availability: candidate.availability,
            quantity: line.quantity - deduped,
            zone,
            category: zone === "companion" ? null : line.category,
            deduped
        });
    }
    return result;
}

/** Oracle ids in the command zone, one entry per card. */
export function commandZoneIds(commandZone: ChosenCommandZone): string[] {
    return [commandZone.commander, commandZone.partner, commandZone.signatureSpell]
        .filter((card): card is CommanderResult => card !== null)
        .map(card => card.id);
}

/**
 * Warnings for each imported line, keyed by line number.
 *
 * - `not_legal` — the card is not legal in the format;
 * - `too_many_copies` — copies across every imported line plus the command
 *   zone exceed the card's `copy_limit`;
 * - `color_identity` — outside the command zone's colours, where the format
 *   enforces colour identity and a commander has been chosen.
 *
 * Warnings never block the import — the deck page flags the same problems
 * once the deck exists.
 */
export function lineWarnings(
    effective: EffectiveLine[],
    commandZone: ChosenCommandZone,
    enforcesColorIdentity: boolean
): Record<number, LineWarning[]> {
    const copies = new Map<string, number>();
    for (const id of commandZoneIds(commandZone)) copies.set(id, (copies.get(id) ?? 0) + 1);
    for (const line of effective) {
        copies.set(line.card.oracle_card_id, (copies.get(line.card.oracle_card_id) ?? 0) + line.quantity);
    }

    const identity = commandZoneIdentity(commandZone);
    const warnings: Record<number, LineWarning[]> = {};
    for (const line of effective) {
        const list: LineWarning[] = [];
        if (!line.card.is_legal) list.push("not_legal");
        if (line.card.copy_limit !== null && (copies.get(line.card.oracle_card_id) ?? 0) > line.card.copy_limit) {
            list.push("too_many_copies");
        }
        if (enforcesColorIdentity && identity !== null && !withinIdentity(line.card.color_identity, identity)) {
            list.push("color_identity");
        }
        warnings[line.line] = list;
    }
    return warnings;
}

/** The command zone's combined colour identity, or null while it is empty. */
export function commandZoneIdentity(commandZone: ChosenCommandZone): string | null {
    if (commandZone.commander === null) return null;
    return [commandZone.commander, commandZone.partner, commandZone.signatureSpell]
        .map(card => card?.color_identity ?? "")
        .join("");
}

/** Whether every colour of `cardIdentity` is in `identity`. Colourless always fits. */
export function withinIdentity(cardIdentity: string | null, identity: string): boolean {
    return [...(cardIdentity ?? "")].every(color => identity.includes(color));
}

/** Card totals per zone, the command zone included in `main` the way the format counts deck size. */
export function zoneTotals(effective: EffectiveLine[], commandZone: ChosenCommandZone): Record<DeckListZone, number> {
    const totals: Record<DeckListZone, number> = { main: commandZoneIds(commandZone).length, side: 0, companion: 0 };
    for (const line of effective) totals[line.zone] += line.quantity;
    return totals;
}

/** The rows the confirm endpoint expects. Lines whose last copy moved to the command zone are left out. */
export function submissionRows(effective: EffectiveLine[]): Array<{
    oracle_card_id: string;
    default_card_id: string;
    quantity: number;
    zone: DeckListZone;
    category: string | null;
}> {
    return effective
        .filter(line => line.quantity > 0)
        .map(line => ({
            oracle_card_id: line.card.oracle_card_id,
            default_card_id: line.card.default_card_id,
            quantity: line.quantity,
            zone: line.zone,
            category: line.category
        }));
}
