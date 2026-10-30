import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { setTestMessages } from "@/test/i18n.ts";
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
            same_printing: [{ container_name: "Binder", amount: 1, finish: "nonfoil", language: "en", proxy: false }],
            other_printings: [
                {
                    default_card_id: "showcase",
                    set_code: "ecl",
                    set_name: "Edge of Eternities: Clash",
                    set_path: null,
                    collector_number: "317",
                    card_image_0: SHOWCASE_IMAGE,
                    container_name: "Binder",
                    amount: 3,
                    finish: "nonfoil",
                    language: "en",
                    proxy: false
                },
                {
                    default_card_id: "promo",
                    set_code: "pecl",
                    set_name: "Promo",
                    set_path: null,
                    collector_number: "145p",
                    card_image_0: PROMO_IMAGE,
                    container_name: null,
                    amount: 1,
                    finish: "nonfoil",
                    language: "en",
                    proxy: false
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

/** The text of the details row whose term is `term` (key echo: the i18n key). */
const detail = (term: string): string | undefined =>
    [...document.querySelectorAll(".cardstack-preview__details dt")]
        .find(dt => dt.textContent?.trim() === term)
        ?.nextElementSibling?.textContent?.replace(/\s+/gu, " ")
        .trim();

/** The Prices section's rows: the market's logo and currency, and the listing beside it. */
const markets = (): [string, string][] =>
    [...document.querySelectorAll(".cardstack-preview__prices dt")].map(dt => [
        `${dt.querySelector("use")!.getAttribute("xlink:href")} ${dt.querySelector('[aria-hidden="true"]')!.textContent!.trim()}`,
        dt.nextElementSibling!.textContent!.replace(/\s+/gu, " ").trim()
    ]);

const noPrices = { nonfoil: null, foil: null, etched: null };

describe("CardPreviewModal — prices", () => {
    beforeEach(() => {
        setTestMessages({
            de: {
                enums: { finishes: { nonfoil: "Normal", foil: "Foil", etched: "Geätzt" } },
                components: { card_preview: { prices: { finish: "{price} {finish}" } } }
            }
        });
    });

    it("lists each market in its own currency, the nonfoil price bare and the other finishes in brackets", async () => {
        await open({
            ...payload(),
            prices: {
                eur: { nonfoil: 15.31, foil: 41.12, etched: null },
                usd: { nonfoil: 17.5, foil: 44, etched: 60 }
            }
        });

        expect(markets()).toEqual([
            ["#cardmarket EUR", "15,31 € (41,12 € Foil)"],
            ["#tcgplayer USD", "17,50 $ (44,00 $ Foil, 60,00 $ Geätzt)"]
        ]);
    });

    it("names the market and its currency in the tooltip, and in words for a screen reader", async () => {
        await open({ ...payload(), prices: { eur: { ...noPrices, nonfoil: 1 }, usd: { ...noPrices, nonfoil: 1 } } });
        const labels = [...document.querySelectorAll(".cardstack-preview__prices dt")];

        expect(labels.map(dt => dt.getAttribute("data-tooltip"))).toEqual([
            "components.card_preview.prices.cardmarket",
            "components.card_preview.prices.tcgplayer"
        ]);
        expect(labels.map(dt => dt.querySelector(".sr-only")!.textContent)).toEqual([
            "components.card_preview.prices.cardmarket",
            "components.card_preview.prices.tcgplayer"
        ]);
    });

    it("lists a printing without a nonfoil price by its finishes alone", async () => {
        await open({ ...payload(), prices: { eur: { ...noPrices, foil: 41.12 }, usd: noPrices } });

        expect(markets()).toEqual([["#cardmarket EUR", "41,12 € Foil"]]);
    });

    it("leaves out a market without a price, and the section when neither has one", async () => {
        await open({ ...payload(), prices: { eur: noPrices, usd: noPrices } });

        expect(document.querySelector(".cardstack-preview__prices")).toBeNull();
    });

    it("keeps a stack's own price in its details, and gives a card none there", async () => {
        const prices = { eur: { ...noPrices, nonfoil: 15.31 }, usd: noPrices };
        await open({ ...payload(), finish: "foil", price: 31.12, prices });
        expect(detail("form.fields.price")).toBe("31,12 €");

        document.body.innerHTML = "";
        await open({ ...payload(), price: 15.31, prices });
        expect(detail("form.fields.price")).toBeUndefined();
    });
});

describe("CardPreviewModal — what your copies are", () => {
    /** The copy markers of the "in your collection" rows, one list per row. */
    const markers = (): string[][] =>
        [...document.querySelectorAll(".cardstack-preview__copies--same li")].map(row =>
            [...row.querySelectorAll(".deck-copies__marker")].map(marker =>
                [...marker.classList]
                    .find(c => c.startsWith("deck-copies__marker--"))!
                    .replace("deck-copies__marker--", "")
            )
        );

    it("marks foil, etched, proxy and foreign-language rows, and leaves a plain English nonfoil unmarked", async () => {
        const row = (finish: string, language = "en", proxy = false) => ({
            container_name: "Binder",
            amount: 1,
            finish,
            language,
            proxy
        });
        await open({
            ...payload(),
            collection: {
                same_printing: [
                    row("nonfoil"),
                    row("foil"),
                    row("etched"),
                    row("nonfoil", "de"),
                    row("nonfoil", "en", true)
                ],
                other_printings: []
            }
        } as unknown as CardPreview);

        expect(markers()).toEqual([[], ["foil"], ["etched"], ["lang-de"], ["proxy"]]);
    });

    it("marks the rows of other printings too", async () => {
        const body = payload();
        body.collection!.other_printings[0] = {
            ...body.collection!.other_printings[0],
            finish: "foil",
            language: "ja",
            proxy: false
        };

        await open(body);

        const row = document.querySelector(".cardstack-preview__copies--printings li")!;
        expect(row.querySelector(".deck-copies__marker--foil")).not.toBeNull();
        expect(row.querySelector(".deck-copies__marker--lang-ja")).not.toBeNull();
    });
});
