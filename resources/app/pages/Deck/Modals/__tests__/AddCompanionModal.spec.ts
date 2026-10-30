import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { DeckCompanion } from "Types/deckPage.ts";
import AddCompanionModal from "../AddCompanionModal.vue";
import DeckCardFreedCopiesModal from "../DeckCardFreedCopiesModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const COMPANION_URL = "/api/decks/deck-1/companion";
const FREED = { reason: "freed_copies_need_a_place", count: 1, container_id: "deckbox", name: "Lurrus of the Dream-Den" };

const jegantha = {
    oracle_card_id: "jegantha",
    name: "Jegantha, the Wellspring",
    color_identity: "R",
    default_card: { card_image_0: null }
} as unknown as DeckCompanion;

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

const render = () =>
    mount(AddCompanionModal, {
        props: {
            deckId: "deck-1",
            format: "commander",
            roster: [jegantha],
            bannedAsCompanion: [],
            enforcesColorIdentity: false,
            commanderColorIdentity: "R"
        },
        global: { stubs: { DeckCardFreedCopiesModal: true } },
        attachTo: document.body
    });

/** Click the roster's one tile — the modal teleports into `<body>`. */
const pick = async (): Promise<void> => {
    document.querySelector<HTMLButtonElement>(".companion-picker__tile")!.click();
    await flushPromises();
};

/** 409 until the request names a place, then the new companion is set. */
const askUntilPlaced = () =>
    http.on(
        COMPANION_URL,
        call =>
            (call.body as { move_to?: string }).move_to === undefined
                ? Response.json(FREED, { status: 409 })
                : Response.json({ companion_oracle_card_id: "jegantha" }),
        "PATCH"
    );

describe("AddCompanionModal — replacing a companion with claimed copies", () => {
    it("asks where the old companion's copies go, then sets the new one with the answer", async () => {
        askUntilPlaced();
        const wrapper = render();
        await pick();

        const question = wrapper.findComponent(DeckCardFreedCopiesModal);
        expect(question.props()).toEqual({ name: "Lurrus of the Dream-Den", count: 1, deckboxId: "deckbox" });
        question.vm.$emit("place", "unsorted");
        await flushPromises();

        expect(http.callsTo(COMPANION_URL).map(call => call.body)).toEqual([
            { oracle_card_id: "jegantha" },
            { oracle_card_id: "jegantha", move_to: "unsorted" }
        ]);
        expect(routerMock.reload).toHaveBeenCalledOnce();
    });

    it("keeps the old companion and the picker open when the question is cancelled", async () => {
        askUntilPlaced();
        const wrapper = render();
        await pick();

        wrapper.findComponent(DeckCardFreedCopiesModal).vm.$emit("close");
        await flushPromises();

        expect(http.callsTo(COMPANION_URL)).toHaveLength(1);
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(wrapper.emitted("close")).toBeUndefined();
        expect(document.querySelector<HTMLButtonElement>(".companion-picker__tile")!.disabled).toBe(false);
    });
});
