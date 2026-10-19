import { afterEach, describe, expect, it, vi } from "vitest";
import { useManaSourcePicks } from "../useManaSourcePicks.ts";

const KEY = (deckId: string): string => `cantrip:deck-mana-source-picks:${deckId}`;
const AUTOMATIC_KEY = (deckId: string): string => `cantrip:deck-mana-source-automatic:${deckId}`;

/**
 * A fresh deck id per test: the composable caches one ref per deck at module
 * level, and has no reason to offer a reset — so tests stay apart by id.
 */
let sequence = 0;
const newDeck = (): string => `deck-${++sequence}`;

afterEach(() => {
    vi.restoreAllMocks();
});

describe("useManaSourcePicks", () => {
    it("starts with no picks", () => {
        expect(useManaSourcePicks(newDeck()).picks.value).toEqual({});
    });

    it("reads the picks stored for the deck", () => {
        const deckId = newDeck();
        localStorage.setItem(KEY(deckId), JSON.stringify({ W: "oracle-1", WU: "oracle-2" }));

        expect(useManaSourcePicks(deckId).picks.value).toEqual({ W: "oracle-1", WU: "oracle-2" });
    });

    it("stores a pick, and drops it again for automatic", () => {
        const deckId = newDeck();
        const { picks, setPick } = useManaSourcePicks(deckId);

        setPick("W", "oracle-1");
        setPick("U", "oracle-2");
        expect(picks.value).toEqual({ W: "oracle-1", U: "oracle-2" });
        expect(JSON.parse(localStorage.getItem(KEY(deckId)) ?? "null")).toEqual({ W: "oracle-1", U: "oracle-2" });

        setPick("W", null);
        expect(picks.value).toEqual({ U: "oracle-2" });
    });

    it("removes the storage entry once the last pick is gone", () => {
        const deckId = newDeck();
        const { setPick } = useManaSourcePicks(deckId);

        setPick("W", "oracle-1");
        setPick("W", null);

        expect(localStorage.getItem(KEY(deckId))).toBeNull();
    });

    it("keeps each deck's picks to itself, and shares them between callers of one deck", () => {
        const first = newDeck();
        const second = newDeck();

        useManaSourcePicks(first).setPick("W", "oracle-1");

        expect(useManaSourcePicks(first).picks.value).toEqual({ W: "oracle-1" });
        expect(useManaSourcePicks(second).picks.value).toEqual({});
    });

    it.each([
        ["not JSON", "{W:"],
        ["an array", '["oracle-1"]'],
        ["a string", '"oracle-1"']
    ])("reads a corrupted entry (%s) as no picks", (_label, raw) => {
        const deckId = newDeck();
        localStorage.setItem(KEY(deckId), raw);

        expect(useManaSourcePicks(deckId).picks.value).toEqual({});
    });

    it("drops entries whose value is not an oracle id string", () => {
        const deckId = newDeck();
        localStorage.setItem(KEY(deckId), JSON.stringify({ W: "oracle-1", U: 3, B: null }));

        expect(useManaSourcePicks(deckId).picks.value).toEqual({ W: "oracle-1" });
    });

    it("still picks when storage refuses to read or write", () => {
        // Private mode, disabled storage, a full quota.
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("denied");
        });
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("denied");
        });
        const { picks, setPick } = useManaSourcePicks(newDeck());

        setPick("W", "oracle-1");

        expect(picks.value).toEqual({ W: "oracle-1" });
    });
});

describe("useManaSourcePicks — resetting every pick", () => {
    it("returns every row to automatic and removes the storage entry", () => {
        const deckId = newDeck();
        const { picks, setPick, clearPicks } = useManaSourcePicks(deckId);
        setPick("W", "oracle-1");
        setPick("WU", "oracle-2");

        clearPicks();

        expect(picks.value).toEqual({});
        expect(localStorage.getItem(KEY(deckId))).toBeNull();
    });
});

describe("useManaSourcePicks — the automatic spell", () => {
    it("defaults to the hardest spell", () => {
        expect(useManaSourcePicks(newDeck()).automaticSpell.value).toBe("hardest");
    });

    it("reads and stores the choice per deck", () => {
        const stored = newDeck();
        localStorage.setItem(AUTOMATIC_KEY(stored), "easiest");
        expect(useManaSourcePicks(stored).automaticSpell.value).toBe("easiest");

        const cheapest = newDeck();
        localStorage.setItem(AUTOMATIC_KEY(cheapest), "cheapest");
        expect(useManaSourcePicks(cheapest).automaticSpell.value).toBe("cheapest");

        const changed = newDeck();
        useManaSourcePicks(changed).setAutomaticSpell("easiest");
        expect(localStorage.getItem(AUTOMATIC_KEY(changed))).toBe("easiest");
        expect(useManaSourcePicks(newDeck()).automaticSpell.value).toBe("hardest");
    });

    it("keeps no entry for the default", () => {
        const deckId = newDeck();
        const { automaticSpell, setAutomaticSpell } = useManaSourcePicks(deckId);
        setAutomaticSpell("easiest");

        setAutomaticSpell("hardest");

        expect(automaticSpell.value).toBe("hardest");
        expect(localStorage.getItem(AUTOMATIC_KEY(deckId))).toBeNull();
    });

    it("reads an unknown stored value as the default", () => {
        const deckId = newDeck();
        localStorage.setItem(AUTOMATIC_KEY(deckId), "fastest");

        expect(useManaSourcePicks(deckId).automaticSpell.value).toBe("hardest");
    });

    it("reads and stores a commander choice, plain or keyed by commander", () => {
        const plain = newDeck();
        localStorage.setItem(AUTOMATIC_KEY(plain), "commander");
        expect(useManaSourcePicks(plain).automaticSpell.value).toBe("commander");

        const keyed = newDeck();
        useManaSourcePicks(keyed).setAutomaticSpell("commander:oracle-1");
        expect(localStorage.getItem(AUTOMATIC_KEY(keyed))).toBe("commander:oracle-1");
        expect(useManaSourcePicks(keyed).automaticSpell.value).toBe("commander:oracle-1");
    });

    it("reads a commander choice without a commander id as the default", () => {
        const deckId = newDeck();
        localStorage.setItem(AUTOMATIC_KEY(deckId), "commander:");

        expect(useManaSourcePicks(deckId).automaticSpell.value).toBe("hardest");
    });

    it("leaves the picks alone", () => {
        const deckId = newDeck();
        const { picks, setPick, setAutomaticSpell } = useManaSourcePicks(deckId);
        setPick("W", "oracle-1");

        setAutomaticSpell("easiest");

        expect(picks.value).toEqual({ W: "oracle-1" });
    });
});
