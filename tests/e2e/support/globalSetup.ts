import { buildAssets, ensureDatabase, resetDatabase, stashStaleHotFile } from "./environment";

/**
 * Prepare the machine for a run: the `public/hot` guard, the database container,
 * a fresh build, and a freshly seeded schema.
 *
 * ORDER MATTERS in two places. The hot-file guard comes first because it decides
 * whether the app will serve from the built manifest at all, and building before
 * settling that would be work spent on assets the page then ignores. The
 * database is reset last so that the app server — which Playwright starts after
 * this returns — never sees a half-migrated schema.
 */
export default async function globalSetup(): Promise<void> {
    await stashStaleHotFile();
    await ensureDatabase();
    buildAssets();
    resetDatabase();
}
