/******************************************************************************
 * Shared path aliases
 *****************************************************************************/
import path from "node:path";
import { fileURLToPath } from "node:url";

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

/**
 * Path aliases shared by the Vite build (`vite.config.ts`) and the Vitest
 * harness (`vitest.config.ts`).
 *
 * Kept in one place so the two cannot drift: a spec importing
 * `Components/UI/Icon.vue` must resolve to the very file the bundle ships,
 * otherwise a green suite would prove nothing about the built app. Mirrors
 * `compilerOptions.paths` in `tsconfig.json` — keep all three in sync when
 * adding an alias.
 */
export const aliases: Record<string, string> = {
    "~": path.resolve(projectRoot, "node_modules"),
    "@": path.resolve(projectRoot, "resources/app"),
    Assets: path.resolve(projectRoot, "resources/app/assets"),
    Components: path.resolve(projectRoot, "resources/app/components"),
    Composables: path.resolve(projectRoot, "resources/app/composables"),
    Abstracts: path.resolve(projectRoot, "resources/app/styles/abstracts"),
    Types: path.resolve(projectRoot, "resources/app/types")
};
