import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { makeCommanderResult } from "@/test/factories/deckList.ts";
import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import CommandZoneField from "../CommandZoneField.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const stubs = { CommanderCommandZonePickerModal: true, OathbreakerCommandZonePickerModal: true };

const render = (props: Record<string, unknown> = {}) =>
    mount(CommandZoneField, {
        props: { format: "commander", withSignatureSpell: false, ...props },
        global: { stubs }
    });

/** The hidden inputs an enclosing `<Form>` submits, as name → value. */
const hiddenInputs = (wrapper: ReturnType<typeof render>) =>
    Object.fromEntries(
        wrapper.findAll("input[type=hidden]").map(input => [input.attributes("name"), input.attributes("value")])
    );

describe("CommandZoneField — commander-family formats", () => {
    it("offers to choose a commander while none is selected", () => {
        const wrapper = render();

        expect(wrapper.find("button").text()).toBe("pages.create_deck.commander.choose");
        expect(hiddenInputs(wrapper)).toEqual({});
    });

    it("shows the commander and partner and offers to change them", () => {
        const commander = makeCommanderResult("Akiri, Fearless Voyager", { companion_type: "partner" });
        const partner = makeCommanderResult("Thrasios, Triton Hero");
        const wrapper = render({ commander, companion: partner });

        expect(wrapper.text()).toContain("Akiri, Fearless Voyager");
        expect(wrapper.text()).toContain("Thrasios, Triton Hero");
        expect(wrapper.text()).toContain("components.commander_picker.partner_selected");
        expect(wrapper.find("button").text()).toBe("pages.create_deck.commander.change");
        expect(hiddenInputs(wrapper)).toEqual({ commander_id: commander.id, companion_id: partner.id });
    });

    it("names the second slot after a background pairing", () => {
        const wrapper = render({
            commander: makeCommanderResult("Wilson", { companion_type: "background" }),
            companion: makeCommanderResult("Raised by Giants")
        });

        expect(wrapper.text()).toContain("components.commander_picker.background_selected");
    });

    it("opens the commander picker and takes its confirmed selection", async () => {
        const wrapper = render();
        await wrapper.find("button").trigger("click");
        const picker = wrapper.findComponent({ name: "CommanderCommandZonePickerModal" });
        expect(picker.props("format")).toBe("commander");

        const commander = makeCommanderResult();
        const partner = makeCommanderResult("Partner");
        picker.vm.$emit("confirm", commander, partner);

        expect(wrapper.emitted("update:commander")).toEqual([[commander]]);
        expect(wrapper.emitted("update:companion")).toEqual([[partner]]);
        expect(wrapper.emitted("confirmed")).toEqual([[commander]]);
        expect(wrapper.findComponent({ name: "OathbreakerCommandZonePickerModal" }).exists()).toBe(false);
    });

    it("shows the commander error under the button", () => {
        expect(render({ errors: { commander_id: "Pick one." } }).text()).toContain("Pick one.");
    });
});

describe("CommandZoneField — Oathbreaker", () => {
    it("shows the planeswalker and signature spell", () => {
        const walker = makeCommanderResult("Chandra, Fire Artisan");
        const spell = makeCommanderResult("Lightning Bolt");
        const wrapper = render({ withSignatureSpell: true, commander: walker, signatureSpell: spell });

        expect(wrapper.text()).toContain("components.oathbreaker_picker.selected_planeswalker");
        expect(wrapper.text()).toContain("components.oathbreaker_picker.selected_spell");
        expect(wrapper.find("button").text()).toBe("pages.create_deck.oathbreaker.change");
        expect(hiddenInputs(wrapper)).toEqual({ commander_id: walker.id, signature_spell_id: spell.id });
    });

    it("opens the Oathbreaker picker and takes its confirmed selection", async () => {
        const wrapper = render({ withSignatureSpell: true });
        await wrapper.find("button").trigger("click");

        const walker: CommanderResult = makeCommanderResult("Chandra");
        const spell = makeCommanderResult("Bolt");
        wrapper.findComponent({ name: "OathbreakerCommandZonePickerModal" }).vm.$emit("confirm", walker, spell);

        expect(wrapper.emitted("update:commander")).toEqual([[walker]]);
        expect(wrapper.emitted("update:signatureSpell")).toEqual([[spell]]);
        expect(wrapper.emitted("update:companion")).toBeUndefined();
        expect(wrapper.findComponent({ name: "CommanderCommandZonePickerModal" }).exists()).toBe(false);
    });
});
