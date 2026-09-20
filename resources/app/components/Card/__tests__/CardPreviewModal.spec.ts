import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import type { CardPreview } from "Types/cardPreview";
import CardPreviewModal from "../CardPreviewModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** The printing the modal itself is about — ECL #145, the normal art. */
const MAIN_IMAGE = "/card-images/ecl/674960ce--0.jpg";
/** A different printing of the same oracle card — ECL #317, the showcase art. */
const SHOWCASE_IMAGE = "/card-images/ecl/68618675--0.jpg";
/** A third printing, so a two-row list can prove each row reads its own entry. */
const PROMO_IMAGE = "/card-images/pecl/145f10ab--0.jpg";

const payload = (): CardPreview =>
    ({
        name: "Hexing Squelcher",
        card_image_0: MAIN_IMAGE,
        card_image_1: null,
        set_code: "ecl",
        set_name: "Edge of Eternities: Clash",
        set_path: null,
        collector_number: "145",
        artist: "Matt Stewart",
        price: 1,
        scryfall_uri: null,
        produced_mana: null,
        is_game_changer: false,
        is_mld: false,
        legalities: [],
        rulings: [],
        collection: {
            same_printing: [{ container_name: "Binder", amount: 1 }],
            other_printings: [
                {
                    default_card_id: "showcase",
                    set_code: "ecl",
                    set_name: "Edge of Eternities: Clash",
                    set_path: null,
                    collector_number: "317",
                    card_image_0: SHOWCASE_IMAGE,
                    container_name: "Binder",
                    amount: 3
                },
                {
                    default_card_id: "promo",
                    set_code: "pecl",
                    set_name: "Promo",
                    set_path: null,
                    collector_number: "145p",
                    card_image_0: PROMO_IMAGE,
                    container_name: null,
                    amount: 1
                }
            ]
        }
    }) as unknown as CardPreview;

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
});

afterEach(() => {
    document.body.innerHTML = "";
});

/** Modal.vue teleports into <body>, so assertions go through `document`. */
const open = async (body: CardPreview = payload()) => {
    http.json("/collection/cardstack/stack-1/preview", body);
    mount(CardPreviewModal, { props: { previewUrl: "/collection/cardstack/stack-1/preview" } });
    await flushPromises();
};

/** Thumbnail `src` values in the "other printings you own" list, in order. */
const thumbSrcs = (): (string | null)[] =>
    [...document.querySelectorAll(".cardstack-preview__copies-thumb")].map(img => img.getAttribute("src"));

describe("CardPreviewModal — other printings you own", () => {
    it("gives each row the thumbnail of the printing that row is about", async () => {
        // Regression: the row is labelled "[ECL] #317" from `entry`, so the
        // image beside it has to come from the same `entry` — not from the
        // card the modal is about, and not from a sibling row.
        await open();

        expect(thumbSrcs()).toEqual([SHOWCASE_IMAGE, PROMO_IMAGE]);
    });

    it("never falls back to the previewed card's own image", async () => {
        await open();

        expect(thumbSrcs()).not.toContain(MAIN_IMAGE);
    });

    it("keeps each row's label and thumbnail on the same printing", async () => {
        // The real invariant: within one row, the "[ECL] #317" text and the
        // image beside it must come from the same entry. Asserting them
        // together is what catches a drift between the two.
        await open();

        const rows = [...document.querySelectorAll(".cardstack-preview__copies--printings li")].map(li => ({
            label: li.querySelector(".cardstack-preview__copies-printing")?.textContent?.trim(),
            thumb: li.querySelector(".cardstack-preview__copies-thumb")?.getAttribute("src")
        }));

        expect(rows).toEqual([
            { label: "[ECL] #317", thumb: SHOWCASE_IMAGE },
            { label: "[PECL] #145p", thumb: PROMO_IMAGE }
        ]);
    });

    it("skips the thumbnail for a printing with no cached image", async () => {
        const body = payload();
        body.collection!.other_printings[0].card_image_0 = null;

        await open(body);

        expect(thumbSrcs()).toEqual([PROMO_IMAGE]);
    });

    it("renders the thumbnail inside a hover preview that escapes the dialog", async () => {
        // The modal is a native <dialog> opened with showModal(), which paints
        // in the top layer above every z-index — a preview teleported to <body>
        // would be invisible behind it.
        await open();

        const trigger = document.querySelector(".cardstack-preview__copies--printings .card-preview__trigger");

        expect(trigger).not.toBeNull();
        expect(trigger?.querySelector(".cardstack-preview__copies-thumb")).not.toBeNull();
    });
});
