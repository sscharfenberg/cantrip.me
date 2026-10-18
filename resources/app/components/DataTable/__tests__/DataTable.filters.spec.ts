import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { h } from "vue";
import { routerMock } from "@/test/inertia.ts";
import type { TableResponse } from "Types/dataTable";
import DataTable from "../DataTable.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

type Row = { id: string; name: string };

const response = (filters: TableResponse<Row>["filters"] = null): TableResponse<Row> => ({
    rows: [{ id: "1", name: "Lightning Bolt" }],
    total: 1,
    page: 3,
    pageSize: 25,
    sort: { key: "name", direction: "asc" },
    search: "bolt",
    filters
});

/**
 * Mount the table with a `#filters` slot that hands its slot props to the
 * test, so the test can call `setFilters` the way a real filter UI would.
 */
const render = (filters: TableResponse<Row>["filters"] = null) => {
    const slot: { filters?: unknown; setFilters?: (patch: Record<string, string | null>) => void } = {};
    mount(DataTable<Row>, {
        props: { columns: [{ key: "name", label: "Name" }], response: response(filters), baseUrl: "/collection" },
        slots: {
            filters: (props: typeof slot) => {
                Object.assign(slot, props);
                return h("div", { class: "filters" });
            }
        }
    });
    return slot;
};

describe("DataTable — #filters slot", () => {
    afterEach(() => {
        window.history.replaceState(null, "", "/");
    });

    it("hands the slot the filters the server applied", () => {
        expect(render({ lang: "de" }).filters).toEqual({ lang: "de" });
        expect(render().filters).toBeNull();
    });

    it("navigates with the change on top of the current query, back on page 1", () => {
        window.history.replaceState(null, "", "/collection?sort=name&dir=asc&search=bolt&page=3&proxy=only");

        render().setFilters!({ lang: "de", proxy: null });

        expect(routerMock.get).toHaveBeenCalledWith(
            "/collection?sort=name&dir=asc&search=bolt&page=1&lang=de",
            {},
            { preserveState: true, preserveScroll: true }
        );
    });
});
