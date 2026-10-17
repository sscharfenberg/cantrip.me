import type { Ref } from "vue";
import { ref } from "vue";
import type { AutomaticSpell } from "@/utils/frankKarstenAnalysis";

/**
 * The spell picked per "Mana sources" row: row key ("W", or a WUBRG-ordered
 * combination like "WU") → the picked card's oracle id. A row without an
 * entry shows its automatic spell.
 */
export type ManaSourcePicks = Readonly<Record<string, string>>;

/** Return type of {@link useManaSourcePicks}. */
export type UseManaSourcePicksReturn = {
    /** The picks for this deck — reactive, initialised from localStorage. */
    picks: Ref<ManaSourcePicks>;
    /** Pick a spell for one row (`null` returns it to automatic) and persist the result. */
    setPick: (key: string, oracleId: string | null) => void;
    /** Return every row to its automatic spell. */
    clearPicks: () => void;
    /** Which spell a row without a pick shows — reactive, initialised from localStorage. */
    automaticSpell: Ref<AutomaticSpell>;
    /** Change which spell rows without a pick show, and persist it. */
    setAutomaticSpell: (choice: AutomaticSpell) => void;
};

/** Prefix for per-deck localStorage entries holding the picks. */
const PICKS_PREFIX = "cantrip:deck-mana-source-picks:";

/** Prefix for per-deck localStorage entries holding the automatic-spell choice. */
const AUTOMATIC_PREFIX = "cantrip:deck-mana-source-automatic:";

/** Values that are considered valid persisted automatic-spell choices. */
const AUTOMATIC_CHOICES: ReadonlySet<AutomaticSpell> = new Set<AutomaticSpell>(["hardest", "easiest", "cheapest"]);

/**
 * Module-level cache, as in `useDeckSort`: every caller for the same deck id
 * shares one reactive state.
 */
const instances = new Map<string, { picks: Ref<ManaSourcePicks>; automaticSpell: Ref<AutomaticSpell> }>();

/**
 * Read the persisted picks for a deck. Anything that is not an object of
 * string values — a hand-edited or corrupted entry — reads as no picks, as
 * does unavailable storage.
 *
 * @param deckId - Deck identifier used as part of the storage key.
 */
function readPicks(deckId: string): ManaSourcePicks {
    try {
        const raw = window.localStorage.getItem(`${PICKS_PREFIX}${deckId}`);
        if (raw === null) return {};
        const parsed: unknown = JSON.parse(raw);
        if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) return {};
        return Object.fromEntries(
            Object.entries(parsed).filter((entry): entry is [string, string] => typeof entry[1] === "string")
        );
    } catch {
        return {};
    }
}

/**
 * Read the persisted automatic-spell choice for a deck; anything unknown,
 * or unavailable storage, reads as the default `hardest`.
 *
 * @param deckId - Deck identifier used as part of the storage key.
 */
function readAutomaticSpell(deckId: string): AutomaticSpell {
    try {
        const raw = window.localStorage.getItem(`${AUTOMATIC_PREFIX}${deckId}`);
        return raw !== null && AUTOMATIC_CHOICES.has(raw as AutomaticSpell) ? (raw as AutomaticSpell) : "hardest";
    } catch {
        return "hardest";
    }
}

/**
 * Persist one localStorage entry, removing it when `value` is null. Fails
 * silently: the state still works for this visit, it just doesn't stick.
 *
 * @param key - The storage key.
 * @param value - The value to store, or null to remove the entry.
 */
function writeEntry(key: string, value: string | null): void {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        // Storage is unavailable or full — nothing else to do.
    }
}

/**
 * The spells picked on a deck's "Mana sources" rows, and which spell the
 * rows without a pick show — per deck and browser.
 *
 * A pick is only a preference: `useDeckStats` ignores one that no card of
 * the row matches any more (the card left the deck, or stopped asking for
 * the color), so nothing here has to prune stale entries.
 *
 * @param deckId - Identifier of the deck whose picks we're tracking.
 */
export function useManaSourcePicks(deckId: string): UseManaSourcePicksReturn {
    let state = instances.get(deckId);
    if (state === undefined) {
        state = {
            picks: ref<ManaSourcePicks>(readPicks(deckId)),
            automaticSpell: ref<AutomaticSpell>(readAutomaticSpell(deckId))
        };
        instances.set(deckId, state);
    }
    const { picks, automaticSpell } = state;

    const storePicks = (next: ManaSourcePicks): void => {
        picks.value = next;
        writeEntry(`${PICKS_PREFIX}${deckId}`, Object.keys(next).length === 0 ? null : JSON.stringify(next));
    };

    const setPick = (key: string, oracleId: string | null): void => {
        const next: Record<string, string> = { ...picks.value };
        if (oracleId === null) {
            delete next[key];
        } else {
            next[key] = oracleId;
        }
        storePicks(next);
    };

    const clearPicks = (): void => storePicks({});

    const setAutomaticSpell = (choice: AutomaticSpell): void => {
        automaticSpell.value = choice;
        // The default needs no entry — keep storage to the decks that changed it.
        writeEntry(`${AUTOMATIC_PREFIX}${deckId}`, choice === "hardest" ? null : choice);
    };

    return { picks, setPick, clearPicks, automaticSpell, setAutomaticSpell };
}
