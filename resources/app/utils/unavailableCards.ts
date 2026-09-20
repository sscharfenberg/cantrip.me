import type {
    CollectionAvailability,
    DeckCardRow,
    DeckCommander,
    DeckCompanion
} from "Types/deckPage.ts";

/**
 * One row of the "unavailable cards" list — a card the deck wants that the
 * collection cannot fully cover.
 */
export interface UnavailableCard {
    /** The deck_card id, unique across every zone including the command zone. */
    id: string;
    name: string;
    /** Copies the deck asks for. Command-zone rows and the companion are always 1. */
    quantity: number;
    setCode: string | null;
    setName: string | null;
    /** The set icon, or null for a printing whose set has none. */
    setPath: string | null;
    collectorNumber: string | null;
    /** Front-face image, for the row thumbnail. */
    image: string | null;
    /**
     * Carried whole rather than flattened to a "missing" count, so the row can
     * render the same `CollectionAvailabilityBadge` the deck page uses and
     * inherit its tooltip. A flattened number would also have to lie about the
     * wrong-printing case, where nothing is missing from the shelf and the
     * deck still cannot be sleeved up as written.
     */
    availability: CollectionAvailability;
}

/** Anything the deck page renders with per-card availability. */
type AvailabilityBearing = DeckCardRow | DeckCommander | DeckCompanion;

/** `DeckCardRow` carries its own id; the command zone calls it `deck_card_id`. */
const idOf = (card: AvailabilityBearing): string =>
    "id" in card ? card.id : card.deck_card_id;

/** Only `DeckCardRow` has a quantity; a commander or companion is one copy. */
const quantityOf = (card: AvailabilityBearing): number =>
    "quantity" in card ? card.quantity : 1;

/**
 * Collect every card the collection cannot fully cover, worst first.
 *
 * "Cannot fully cover" is both non-green states, which is wider than the word
 * "unavailable" suggests: `unavailable` is nothing free at all, `partial` is
 * some copies free but not enough of the deck's own printing — including the
 * case where the shelf has the card only in a different printing. Both belong
 * on a shopping list; the badge on each row tells them apart.
 *
 * A card with no availability at all is skipped rather than assumed missing.
 * That is the whole gate for the feature: the deck page leaves
 * `collection_availability` null unless the deck is planned, the viewer owns
 * it and the collection-integration master switch is on — so an empty result
 * is also the signal to hide the menu entry that opens this list.
 *
 * Ordering is `unavailable` before `partial`, then by name, so the cards you
 * own nothing of are read first. Sorting is done on a copy; the arrays handed
 * in are page props and are left untouched.
 */
export function collectUnavailableCards(
    cards: DeckCardRow[] = [],
    commanders: DeckCommander[] = [],
    companion: DeckCompanion | null = null
): UnavailableCard[] {
    const everything: AvailabilityBearing[] = [...commanders, ...(companion ? [companion] : []), ...cards];

    return everything
        .filter(card => card.collection_availability !== null && card.collection_availability.state !== "available")
        .map(card => ({
            id: idOf(card),
            name: card.name,
            quantity: quantityOf(card),
            setCode: card.default_card.set?.code ?? null,
            setName: card.default_card.set?.name ?? null,
            setPath: card.default_card.set?.path ?? null,
            collectorNumber: card.default_card.collector_number,
            image: card.default_card.card_image_0,
            availability: card.collection_availability!
        }))
        .sort((a, b) => {
            if (a.availability.state !== b.availability.state) {
                return a.availability.state === "unavailable" ? -1 : 1;
            }

            return a.name.localeCompare(b.name);
        });
}
