/**
 * Oracle names of the ten Magic "Companion" keyword cards.
 *
 * Mirrors `App\Companions\CompanionRegistry::NAMES`. Kept frontend-local so
 * UI-only checks (e.g. "the card you picked as commander is also a companion")
 * don't need a server round-trip. The list is stable — Wizards has not added
 * to it since the Ikoria cycle.
 */
export const COMPANION_NAMES = [
    "Gyruda, Doom of Depths",
    "Jegantha, the Wellspring",
    "Kaheera, the Orphanguard",
    "Keruga, the Macrosage",
    "Lurrus of the Dream-Den",
    "Lutri, the Spellchaser",
    "Obosh, the Preypiercer",
    "Umori, the Collector",
    "Yorion, Sky Nomad",
    "Zirda, the Dawnwaker",
] as const;

export function isCompanionCard(name: string): boolean {
    return (COMPANION_NAMES as readonly string[]).includes(name);
}