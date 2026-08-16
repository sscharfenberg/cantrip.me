import { default as vitePluginVue } from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";
import { aliases } from "./resources/build/aliases.ts";

/*
 * Vitest harness — deliberately a separate config from `vite.config.ts`.
 *
 * The build config carries three plugins that are wrong or useless under test:
 * `laravel-vite-plugin` (writes `public/hot`, expects a Laravel manifest),
 * `vite-plugin-image-optimizer` (would run sharp over every asset) and the Vue
 * devtools plugin. What must stay in sync between the two configs is the alias
 * map and the Vue compiler options; both are imported/mirrored rather than
 * retyped.
 *
 * CSS is left at Vitest's default (`css: false`), so `<style lang="scss">`
 * blocks in SFCs are stubbed out instead of compiled — specs assert on markup
 * and behaviour, never on computed styles, and skipping sass keeps the suite
 * fast.
 *
 * https://vitest.dev/config/
 */
export default defineConfig({
    plugins: [
        vitePluginVue({
            template: {
                compilerOptions: {
                    // Mirrors vite.config.ts — `<media-*>` tags are custom
                    // elements, not Vue components.
                    isCustomElement: tag => tag.startsWith("media-")
                }
            }
        })
    ],

    resolve: {
        alias: aliases
    },

    test: {
        environment: "jsdom",

        // Specs import `describe`/`it`/`expect` from "vitest" explicitly, so
        // neither ESLint nor tsconfig needs to learn about test globals.
        globals: false,

        setupFiles: ["./resources/app/test/setup.ts"],
        include: ["resources/app/**/__tests__/**/*.spec.ts"],

        // Every spy/stub is undone between tests, so a forgotten cleanup in one
        // spec cannot leak into the next.
        restoreMocks: true,
        unstubGlobals: true,
        unstubEnvs: true,

        coverage: {
            provider: "v8",

            /*
             * `.ts` only, deliberately.
             *
             * Anything listed here that no test imported is read straight off
             * disk and parsed as JavaScript to synthesise its 0% baseline. That
             * works for TypeScript and not at all for single-file components:
             * rolldown rejects some outright (`Guest/Welcome.vue` →
             * "Unexpected JSX expression") and silently counts `<template>` and
             * `<style>` markup as statements in the rest, which drags the
             * denominator up by ~4 600 phantom statements.
             *
             * Components a spec *does* mount are measured correctly — the
             * broken path is only the untested-file baseline. So this is a
             * reporting compromise, not a statement about what is testable.
             */
            include: ["resources/app/**/*.ts"],
            exclude: [
                "resources/app/**/__tests__/**",
                "resources/app/test/**",
                "resources/app/lang/**",
                // Bootstrap glue: mounts the Inertia app and registers router
                // listeners. Exercised end-to-end by the app itself, not here.
                "resources/app/main.ts"
            ],
            reporter: ["text-summary", "html"],
            reportsDirectory: "coverage"
        }
    }
});
