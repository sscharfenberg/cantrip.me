/** One card line of the printable deck list — a single deck row. */
export interface PrintableCard {
    name: string;
    quantity: number;
    /** Set code of the chosen printing; null when the row has no printing. */
    set_code: string | null;
    collector_number: string | null;
}

/** A headed block of cards — a type group, a custom category, the command zone, … */
export interface PrintableGroup {
    label: string;
    cards: PrintableCard[];
}

/** Everything the printable text is built from, already translated. */
export interface PrintableDeck {
    name: string;
    /** Free-text deck description; the block is omitted when empty. */
    description: string | null;
    /** Pre-formatted metadata lines ("Format: Commander", …). */
    meta: string[];
    /** Mainboard groups in display order (command zone and companion first). */
    groups: PrintableGroup[];
    /** Sideboard, printed apart from the mainboard; null when there is none. */
    sideboard: PrintableGroup | null;
}

/** Separates the deck's top-level blocks: name, description, metadata, sideboard. */
export const MAJOR_DIVIDER = "=".repeat(40);
/** Separates a group headline from its cards. */
export const MINOR_DIVIDER = "-".repeat(40);

/**
 * Format one card as `amount name (SET) collector-number` — the line shape
 * Moxfield, Archidekt and MTG Arena all import, so the text doubles as an
 * export. The printing suffix is dropped when the row has no printing.
 */
export function formatCardLine(card: PrintableCard): string {
    const line = `${card.quantity} ${card.name}`;
    if (card.set_code === null) return line;
    const printing = `(${card.set_code.toUpperCase()})`;
    return card.collector_number === null ? `${line} ${printing}` : `${line} ${printing} ${card.collector_number}`;
}

/** Group headline, a divider, then one line per card. */
function formatGroup(group: PrintableGroup): string {
    const count = group.cards.reduce((sum, card) => sum + card.quantity, 0);
    return [`${group.label} (${count})`, MINOR_DIVIDER, ...group.cards.map(formatCardLine)].join("\n");
}

/**
 * Build the plain-text deck list: name, description, metadata, the
 * mainboard groups, and — set apart by a major divider — the sideboard.
 * Empty groups are skipped so a headline never stands alone.
 */
export function buildPrintableDeck(deck: PrintableDeck): string {
    const blocks = [deck.name];
    const description = deck.description?.trim() ?? "";
    if (description !== "") blocks.push(description);
    blocks.push(deck.meta.join("\n"));

    const groups = deck.groups.filter(group => group.cards.length > 0).map(formatGroup);
    let text = blocks.join(`\n${MAJOR_DIVIDER}\n`) + `\n${MAJOR_DIVIDER}\n`;
    if (groups.length > 0) text += "\n" + groups.join("\n\n") + "\n";
    if (deck.sideboard !== null && deck.sideboard.cards.length > 0) {
        text += `\n${MAJOR_DIVIDER}\n\n${formatGroup(deck.sideboard)}\n`;
    }
    return text;
}
