import { describe, expect, it } from "vitest";
import type { LandCandidate } from "../fetchlandResolver.ts";
import { buildFetchBuckets, makeFetchResolver, parsePatternColors, resolveFetchPattern } from "../fetchlandResolver.ts";

/** Shorthand for a land row; only the three fields the resolver reads. */
const land = (type_line: string, produced_mana: string[] | null, is_basic_land = false): LandCandidate => ({
    type_line,
    produced_mana,
    is_basic_land
});

const PLAINS = land("Basic Land — Plains", ["W"], true);
const ISLAND = land("Basic Land — Island", ["U"], true);
const SNOW_SWAMP = land("Basic Snow Land — Swamp", ["B"], true);
const HALLOWED_FOUNTAIN = land("Land — Plains Island", ["W", "U"]);
const RAUGRIN_TRIOME = land("Land — Island Mountain Plains", ["U", "R", "W"]);
/** The one basic with no land subtype — reaches `anyBasic` but no `byBasicType` bucket. */
const WASTES = land("Basic Land", ["C"], true);
const COMMAND_TOWER = land("Land", ["W", "U", "B", "R", "G"]);

/** Sorted, so assertions don't depend on Set insertion order. */
const sorted = (colors: string[]): string[] => [...colors].sort();

describe("parsePatternColors", () => {
    it("splits a WUBRG tail into letters", () => {
        expect(parsePatternColors("WUG")).toEqual(["W", "U", "G"]);
        expect(parsePatternColors("W")).toEqual(["W"]);
    });

    it("returns nothing for an empty tail", () => {
        expect(parsePatternColors("")).toEqual([]);
    });

    it("drops letters outside WUBRG instead of throwing", () => {
        // Defensive: the tail comes from a DB column synced off Scryfall tags.
        expect(parsePatternColors("WXQ")).toEqual(["W"]);
        expect(parsePatternColors("C")).toEqual([]);
        expect(parsePatternColors("wu")).toEqual([]);
    });

    it("preserves the order it was given rather than normalising to WUBRG", () => {
        expect(parsePatternColors("GW")).toEqual(["G", "W"]);
    });
});

describe("buildFetchBuckets", () => {
    it("returns empty buckets for a deck with no lands", () => {
        const buckets = buildFetchBuckets([]);

        expect(buckets.anyLand.size).toBe(0);
        expect(buckets.anyBasic.size).toBe(0);
        expect(buckets.byType.W.size).toBe(0);
        expect(buckets.byBasicType.W.size).toBe(0);
    });

    it("skips lands that produce no mana at all", () => {
        // Maze of Ith and friends: real lands, zero produced_mana.
        const buckets = buildFetchBuckets([land("Land", null), PLAINS]);

        expect(sorted([...buckets.anyLand])).toEqual(["W"]);
    });

    it("files a basic into every bucket its subtype implies", () => {
        const buckets = buildFetchBuckets([ISLAND]);

        expect([...buckets.anyLand]).toEqual(["U"]);
        expect([...buckets.anyBasic]).toEqual(["U"]);
        expect([...buckets.byType.U]).toEqual(["U"]);
        expect([...buckets.byBasicType.U]).toEqual(["U"]);
        expect(buckets.byType.W.size).toBe(0);
    });

    it("keeps non-basics out of the basic-only buckets", () => {
        const buckets = buildFetchBuckets([HALLOWED_FOUNTAIN]);

        expect(sorted([...buckets.anyLand])).toEqual(["U", "W"]);
        expect(buckets.anyBasic.size).toBe(0);
        // A shockland is both a Plains and an Island, and produces both
        // colours — so either subtype bucket yields the full pair.
        expect(sorted([...buckets.byType.W])).toEqual(["U", "W"]);
        expect(sorted([...buckets.byType.U])).toEqual(["U", "W"]);
        expect(buckets.byBasicType.W.size).toBe(0);
    });

    it("matches subtypes as substrings, so Snow-Covered basics still count", () => {
        const buckets = buildFetchBuckets([SNOW_SWAMP]);

        expect([...buckets.byBasicType.B]).toEqual(["B"]);
    });

    it("files a Triome into all three of its subtype buckets", () => {
        const buckets = buildFetchBuckets([RAUGRIN_TRIOME]);

        for (const color of ["U", "R", "W"] as const) {
            expect(sorted([...buckets.byType[color]])).toEqual(["R", "U", "W"]);
        }
        expect(buckets.byType.B.size).toBe(0);
    });

    it("records colourless production, so Wastes is not silently dropped", () => {
        const buckets = buildFetchBuckets([WASTES]);

        expect([...buckets.anyLand]).toEqual(["C"]);
        expect([...buckets.anyBasic]).toEqual(["C"]);
        // It carries no land subtype, so no per-subtype bucket claims it.
        for (const color of ["W", "U", "B", "R", "G"] as const) {
            expect(buckets.byBasicType[color].size).toBe(0);
        }
    });

    it("unions production across the whole deck", () => {
        const buckets = buildFetchBuckets([PLAINS, ISLAND, SNOW_SWAMP, COMMAND_TOWER]);

        expect(sorted([...buckets.anyLand])).toEqual(["B", "G", "R", "U", "W"]);
        expect(sorted([...buckets.anyBasic])).toEqual(["B", "U", "W"]);
    });
});

describe("resolveFetchPattern", () => {
    const buckets = buildFetchBuckets([PLAINS, ISLAND, SNOW_SWAMP, HALLOWED_FOUNTAIN, COMMAND_TOWER]);

    it("resolves `basic` to every basic in the deck", () => {
        // Fabled Passage: fetches any basic, so it produces whatever the
        // deck's basics do — and nothing a non-basic adds.
        expect(sorted(resolveFetchPattern("basic", buckets))).toEqual(["B", "U", "W"]);
    });

    it("resolves `any` to every land in the deck", () => {
        // Urza's Cave: Command Tower's five colours are in reach.
        expect(sorted(resolveFetchPattern("any", buckets))).toEqual(["B", "G", "R", "U", "W"]);
    });

    it("resolves `basic:<colors>` to basics of just those subtypes", () => {
        expect(sorted(resolveFetchPattern("basic:U", buckets))).toEqual(["U"]);
        expect(sorted(resolveFetchPattern("basic:UB", buckets))).toEqual(["B", "U"]);
    });

    it("resolves `typed:<colors>` to any land carrying those subtypes, basic or not", () => {
        // Flooded Strand — the shockland is a Plains *and* an Island, so
        // fetching on either subtype gets both its colours.
        expect(sorted(resolveFetchPattern("typed:WU", buckets))).toEqual(["U", "W"]);
    });

    it("distinguishes typed from basic on the same colour", () => {
        const withOnlyShockland = buildFetchBuckets([HALLOWED_FOUNTAIN]);

        expect(sorted(resolveFetchPattern("typed:W", withOnlyShockland))).toEqual(["U", "W"]);
        expect(resolveFetchPattern("basic:W", withOnlyShockland)).toEqual([]);
    });

    it("returns nothing for a pattern with an empty or unparseable colour tail", () => {
        expect(resolveFetchPattern("typed:", buckets)).toEqual([]);
        expect(resolveFetchPattern("basic:", buckets)).toEqual([]);
        expect(resolveFetchPattern("basic:X", buckets)).toEqual([]);
    });

    it("returns nothing for an unknown pattern rather than throwing", () => {
        expect(resolveFetchPattern("", buckets)).toEqual([]);
        expect(resolveFetchPattern("nonesuch", buckets)).toEqual([]);
        // The prefix check is exact — a near-miss must not fall through to a
        // wrong bucket.
        expect(resolveFetchPattern("basics", buckets)).toEqual([]);
    });
});

describe("makeFetchResolver", () => {
    const lands = [PLAINS, ISLAND, HALLOWED_FOUNTAIN];

    it("returns null when the deck holds no fetchland, so callers can skip the work", () => {
        expect(makeFetchResolver([{ fetch_pattern: null }, {}], lands)).toBeNull();
        expect(makeFetchResolver([], lands)).toBeNull();
    });

    it("treats an empty-string pattern as no fetchland", () => {
        expect(makeFetchResolver([{ fetch_pattern: "" }], lands)).toBeNull();
    });

    it("returns a resolver as soon as one card carries a pattern", () => {
        const resolve = makeFetchResolver([{ fetch_pattern: null }, { fetch_pattern: "basic" }], lands);

        expect(resolve).not.toBeNull();
        expect(sorted(resolve!("basic"))).toEqual(["U", "W"]);
    });

    it("memoises per pattern, so several fetchlands sharing one resolve once", () => {
        const resolve = makeFetchResolver([{ fetch_pattern: "basic" }], lands)!;

        // Identity, not equality: a fresh array would mean the cache missed.
        expect(resolve("basic")).toBe(resolve("basic"));
    });

    it("keeps a separate entry per pattern", () => {
        const resolve = makeFetchResolver([{ fetch_pattern: "basic" }], lands)!;

        // `basic` sees only the two basics; `typed:W` also picks up the
        // shockland — one cache entry must not answer for the other.
        expect(sorted(resolve("basic"))).toEqual(["U", "W"]);
        expect(sorted(resolve("typed:W"))).toEqual(["U", "W"]);
        expect(sorted(resolve("typed:U"))).toEqual(["U", "W"]);
        expect(resolve("basic:U")).toEqual(["U"]);
    });

    it("caches the empty result of an unknown pattern too", () => {
        const resolve = makeFetchResolver([{ fetch_pattern: "basic" }], lands)!;

        expect(resolve("nonesuch")).toEqual([]);
        expect(resolve("nonesuch")).toBe(resolve("nonesuch"));
    });
});
