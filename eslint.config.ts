import { defineConfigWithVueTs, vueTsConfigs } from "@vue/eslint-config-typescript";
import prettier from "eslint-config-prettier";
import importPlugin from "eslint-plugin-import-x";
import pluginVue from "eslint-plugin-vue";
import { globalIgnores } from "eslint/config";
// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-ignore
import skipFormatting from "@vue/eslint-config-prettier/skip-formatting";

// To allow more languages other than `ts` in `.vue` files, uncomment the following lines:
// import { configureVueProject } from '@vue/eslint-config-typescript'
// configureVueProject({ scriptLangs: ['ts', 'tsx'] })
// More info at https://github.com/vuejs/eslint-config-typescript/#advanced-setup

export default defineConfigWithVueTs(
    {
        files: ["**/*.{ts,mts,tsx,vue}"]
    },
    globalIgnores(["**/dist/**", "**/dist-ssr/**", "**/coverage/**"]),
    pluginVue.configs["flat/essential"],
    vueTsConfigs.recommended,
    skipFormatting,
    /*
     * `eslint-plugin-import-x` rather than the better-known `eslint-plugin-import`, which is the
     * same rules under a maintained fork. The choice is forced by peer ranges, not taste: the
     * original declares `eslint: ^2 || … || ^9` and so pins the whole toolchain to ESLint 9,
     * while every other plugin here already accepts 10. One unmaintained dependency deciding the
     * linter's major version for all the others is the thing being avoided.
     *
     * The surface is two settings and one rule, and `import-x/order` takes the same options, so
     * the ordering it enforces is unchanged.
     */
    {
        plugins: {
            "import-x": importPlugin
        },
        settings: {
            "import-x/resolver": {
                typescript: {
                    alwaysTryTypes: true,
                    project: "./tsconfig.json"
                }
            }
        },
        rules: {
            "vue/multi-word-component-names": "off",
            "@typescript-eslint/no-explicit-any": "off",
            "@typescript-eslint/consistent-type-imports": [
                "error",
                {
                    prefer: "type-imports",
                    fixStyle: "separate-type-imports"
                }
            ],
            "import-x/order": [
                "error",
                {
                    groups: ["builtin", "external", "internal", "parent", "sibling", "index"],
                    alphabetize: {
                        order: "asc",
                        caseInsensitive: true
                    }
                }
            ]
        }
    },
    prettier
);
