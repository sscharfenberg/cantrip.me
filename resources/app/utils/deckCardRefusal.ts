/**
 * Reasons the deck card endpoints refuse to add a copy, sent as `reason` on a
 * 422 by both POST /api/decks/{deck}/cards and the quantity PATCH —
 * `App\Formats\Capabilities\AddCopyFailure`. Each has a message under
 * `pages.deck.add_refused`.
 */
const REFUSAL_REASONS = [
    "exceeds_max_copies",
    "violates_singleton",
    "not_in_pool",
    "exceeds_deck_size",
    "violates_color_identity"
] as const;

/** One of {@link REFUSAL_REASONS}. */
export type RefusalReason = (typeof REFUSAL_REASONS)[number];

/**
 * i18n key of the message explaining a refused add, read from the 422 body.
 *
 * Falls back to `pages.deck.add_refused.generic` for a body that is missing,
 * not JSON, or names a reason this client does not know yet. The
 * `exceeds_deck_size` message interpolates `{max}`; pass the deck's ceiling.
 *
 * @param response - The refused (422) response; its body is consumed.
 * @return The message key to hand to `t()`.
 */
export async function refusalMessageKey(response: Response): Promise<string> {
    const data = (await response.json().catch(() => ({}))) as { reason?: unknown };
    const reason = REFUSAL_REASONS.find(r => r === data.reason);
    return `pages.deck.add_refused.${reason ?? "generic"}`;
}
