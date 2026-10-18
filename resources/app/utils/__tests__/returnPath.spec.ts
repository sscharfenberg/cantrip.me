import { afterEach, describe, expect, it } from "vitest";
import { currentPath, withReturn } from "@/utils/returnPath.ts";

const visit = (url: string): void => history.replaceState(null, "", url);

afterEach(() => visit("/"));

describe("currentPath", () => {
    it("is the path and query the server sees — the hash stays behind", () => {
        visit("/collection?search=sol+ring&page=2#top");

        expect(currentPath()).toBe("/collection?search=sol+ring&page=2");
    });
});

describe("withReturn", () => {
    it("points `return` at the current page", () => {
        visit("/collection?search=sol+ring&set=lea&page=2");

        const url = new URL(withReturn("/collection/cardstack/s1/edit"), "http://x");

        expect(url.pathname).toBe("/collection/cardstack/s1/edit");
        expect(url.searchParams.get("return")).toBe("/collection?search=sol+ring&set=lea&page=2");
    });

    it("keeps the query the URL already has", () => {
        const url = new URL(withReturn("/collection/cardstack/s1/claims?keep=1", "/containers/c1"), "http://x");

        expect(url.searchParams.get("keep")).toBe("1");
        expect(url.searchParams.get("return")).toBe("/containers/c1");
    });

    it("replaces a `return` the URL already carries", () => {
        const url = new URL(withReturn("/x?return=%2Fold", "/new"), "http://x");

        expect(url.searchParams.getAll("return")).toEqual(["/new"]);
    });

    it("encodes the page so its own query survives as one value", () => {
        expect(withReturn("/x", "/collection?search=a&page=2")).toBe("/x?return=%2Fcollection%3Fsearch%3Da%26page%3D2");
    });
});
