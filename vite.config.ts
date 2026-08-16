import { default as vitePluginVue } from "@vitejs/plugin-vue";
import laravelPlugin from "laravel-vite-plugin";
import { Features } from "lightningcss";
import { defineConfig, loadEnv } from "vite";
import { ViteImageOptimizer } from "vite-plugin-image-optimizer";
import vitePluginVueDevtools from "vite-plugin-vue-devtools";
import { aliases } from "./resources/build/aliases.ts";

/*
 * https://vite.dev/config/
 */
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), "");

    return {
        /*
         * https://vite.dev/config/shared-options.html#root
         */
        // root: "resources/app",

        /*
         * https://vite.dev/config/shared-options.html#publicdir
         */
        // publicDir: "resources/app/static/",

        /*
         * https://vite.dev/config/shared-options.html#plugins
         */
        plugins: [
            // https://github.com/laravel/vite-plugin
            // https://laravel.com/docs/12.x/vite
            laravelPlugin({
                input: ["resources/app/main.ts"],
                refresh: true
            }),

            // https://github.com/vitejs/vite/tree/main/packages/plugin-vue
            vitePluginVue({
                template: {
                    transformAssetUrls: {
                        // The Vue plugin will re-write asset URLs, when referenced
                        // in Single File Components, to point to the Laravel web
                        // server. Setting this to `null` allows the Laravel plugin
                        // to instead re-write asset URLs to point to the Vite
                        // server instead.
                        base: null,

                        // The Vue plugin will parse absolute URLs and treat them
                        // as absolute paths to files on disk. Setting this to
                        // `false` will leave absolute URLs untouched so they can
                        // reference assets in the public directly as expected.
                        includeAbsolute: false
                    },
                    compilerOptions: {
                        isCustomElement: tag => tag.startsWith("media-")
                    }
                }
            }),

            // https://devtools.vuejs.org/
            // https://www.npmjs.com/package/vite-plugin-vue-devtools
            vitePluginVueDevtools(),

            // https://www.npmjs.com/package/vite-plugin-image-optimizer
            ViteImageOptimizer({
                test: /\.(jpe?g|png|webp|svg|avif)$/i,
                exclude: undefined,
                include: undefined,
                includePublic: true,
                logStats: true,
                ansiColors: true,
                svg: {
                    multipass: true,
                    plugins: [
                        {
                            name: "preset-default",
                            params: {
                                overrides: {
                                    cleanupNumericValues: false
                                },
                                cleanupIDs: {
                                    minify: false,
                                    remove: false
                                },
                                convertPathData: false
                            }
                        },
                        "sortAttrs",
                        {
                            name: "addAttributesToSVGElement",
                            params: {
                                attributes: [{ xmlns: "http://www.w3.org/2000/svg" }]
                            }
                        }
                    ]
                },
                png: {
                    // https://sharp.pixelplumbing.com/api-output#png
                    quality: 100
                },
                jpeg: {
                    // https://sharp.pixelplumbing.com/api-output#jpeg
                    quality: 60
                },
                jpg: {
                    // https://sharp.pixelplumbing.com/api-output#jpeg
                    quality: 60
                },
                webp: {
                    // https://sharp.pixelplumbing.com/api-output#webp
                    lossless: true
                },
                avif: {
                    // https://sharp.pixelplumbing.com/api-output#avif
                    lossless: true
                }
            })
        ],

        /*
         * https://vite.dev/config/shared-options.html#resolve-alias
         * Shared with `vitest.config.ts` — see `resources/build/aliases.ts`.
         */
        resolve: {
            alias: aliases
        },

        optimizeDeps: {
            exclude: ["js-big-decimal"]
        },

        /*
         * https://vite.dev/config/build-options.html
         */
        css: {
            lightningcss: {
                exclude: Features.LightDark
            }
        },

        build: {
            // outDir: "public",
            emptyOutDir: true
        },

        server: {
            // When VITE_SERVER_ORIGIN is set, Vite advertises that URL in
            // public/hot so Laravel generates correct HTTPS asset URLs.
            origin: env.VITE_SERVER_ORIGIN || undefined,
            cors: true,
            watch: {
                ignored: [
                    "**/vendor/**",
                    "**/storage/**",
                    "**/node_modules/**",
                    "**/public/art-crops/**",
                    "**/public/card-images/**",
                    "**/public/set/**",
                    "**/public/symbol/**",
                    "**/public/storage/**"
                ]
            }
        }
    };
});
