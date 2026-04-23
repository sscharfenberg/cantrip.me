const WUBRG = ["W", "U", "B", "R", "G"] as const;

/**
 * Whether a card's color identity fits within a (combined) commander identity.
 * Colorless cards always fit.
 */
export function isSubsetCI(cardCI: string | null, commanderCI: string): boolean {
    if (!cardCI) return true;
    return cardCI.split("").every((letter) => commanderCI.includes(letter));
}

/**
 * Combine a set of partial identities (e.g. each commander's) into a single
 * WUBRG-ordered string.
 */
export function combineCI(parts: (string | null)[]): string {
    const letters = new Set<string>();
    for (const part of parts) {
        if (!part) continue;
        for (const letter of part) letters.add(letter);
    }
    return WUBRG.filter((letter) => letters.has(letter)).join("");
}