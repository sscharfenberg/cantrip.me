// @vitest-environment node

import { describe, expect, it } from "vitest";
import { refusalMessageKey } from "../deckCardRefusal.ts";

const refused = (body: string): Response =>
    new Response(body, { status: 422, headers: { "Content-Type": "application/json" } });

describe("refusalMessageKey", () => {
    it.each([
        "exceeds_max_copies",
        "violates_singleton",
        "not_in_pool",
        "exceeds_deck_size",
        "violates_color_identity"
    ])("names the message for %s", async reason => {
        expect(await refusalMessageKey(refused(JSON.stringify({ reason })))).toBe(`pages.deck.add_refused.${reason}`);
    });

    it("falls back to the generic message for a reason it does not know", async () => {
        expect(await refusalMessageKey(refused(JSON.stringify({ reason: "something_new" })))).toBe(
            "pages.deck.add_refused.generic"
        );
    });

    it("falls back to the generic message for a validation error without a reason", async () => {
        expect(await refusalMessageKey(refused(JSON.stringify({ errors: { zone: ["invalid"] } })))).toBe(
            "pages.deck.add_refused.generic"
        );
    });

    it("falls back to the generic message for a body that is not JSON", async () => {
        expect(await refusalMessageKey(refused("<html>"))).toBe("pages.deck.add_refused.generic");
    });
});
