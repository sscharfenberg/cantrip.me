import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { routerMock } from "@/test/inertia.ts";
import CardStackPage from "../CardStackPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const EDIT_URL = "/collection/cardstack/s1/edit?return=%2Fcollection%3Fsearch%3Dsol%2Bring";

const cardStack = (claims: { deck_id: string; deck_name: string }[] = []) => ({
    id: "s1",
    amount: 2,
    language: "en",
    condition: "",
    finish: "nonfoil",
    container_id: "c1",
    proxy: false,
    claims,
    default_card: {
        id: "p1",
        name: "Sol Ring",
        card_image_0: "/card-images/lea/p1.jpg",
        card_image_1: null,
        artist: "Mark Tedin",
        cn: "269",
        owned: null,
        finishes: ["nonfoil"],
        set: { name: "Limited Edition Alpha", code: "lea", path: null },
        available_langs: ["en"]
    }
});

const render = (props: Record<string, unknown> = {}) => {
    history.replaceState(null, "", EDIT_URL);
    return mount(CardStackPage, {
        props: {
            container: null,
            containers: [{ id: "c1", name: "Binder" }],
            conditions: ["near_mint"],
            finishes: ["nonfoil", "foil"],
            languages: ["en"],
            cardStack: cardStack(),
            ...props
        },
        attachTo: document.body
    });
};

afterEach(() => history.replaceState(null, "", "/"));

describe("CardStackPage — where saving an edit leads", () => {
    it("sends the page the edit was opened from along with the save", () => {
        const input = render({ returnTo: "/collection?search=sol+ring" }).find('input[type="hidden"][name="return"]');

        expect(input.exists()).toBe(true);
        expect((input.element as HTMLInputElement).value).toBe("/collection?search=sol+ring");
    });

    it("sends none when the edit was opened directly, so the save goes to the container", () => {
        expect(render({ returnTo: null }).find('input[name="return"]').exists()).toBe(false);
    });

    it("unclaims back onto this edit page, which keeps the page it was opened from", async () => {
        const wrapper = render({
            returnTo: "/collection?search=sol+ring",
            cardStack: cardStack([{ deck_id: "d1", deck_name: "Krenko" }])
        });

        const button = wrapper.findAll("button").find(b => b.text().includes("pages.collection.claim_badge.unclaim"));
        await button!.trigger("click");

        const url = new URL(routerMock.delete.mock.calls[0][0] as string, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/s1/claims");
        expect(url.searchParams.get("return")).toBe(EDIT_URL);
    });
});
