<script lang="ts" setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
const startYear = 2026;
const currentYear = new Date().getFullYear();
let copyrightDate = `${startYear}`;
if (currentYear > startYear) {
    copyrightDate += " - " + currentYear;
}
const page = usePage();
const version = computed(() => page.props.version as string);
</script>

<template>
    <footer>
        <section class="inner">
            <span class="meta">&copy; cantrip.me contributors {{ copyrightDate }} · v{{ version }}</span>
            <link-group :label="$t('footer.nav_label')">
                <labelled-link href="https://bsky.app/profile/cantrip.me" :aria-label="$t('footer.bluesky')" icon=""
                    ><icon name="bluesky"
                /></labelled-link>
                <labelled-link href="https://discord.gg/jCc5fmXyq" :aria-label="$t('footer.discord')" icon=""
                    ><icon name="discord"
                /></labelled-link>
                <labelled-link
                    href="https://github.com/sscharfenberg/cantrip.me"
                    :aria-label="$t('footer.github')"
                    icon=""
                    ><icon name="github"
                /></labelled-link>
                <labelled-link href="/about">{{ $t("pages.about.link") }}</labelled-link>
                <labelled-link href="/privacy">{{ $t("pages.privacy.link") }}</labelled-link>
                <labelled-link href="/imprint">{{ $t("pages.imprint.link") }}</labelled-link>
            </link-group>
        </section>
        <section class="inner gap">
            <i18n-t keypath="footer.disclaimer" scope="global">
                <template #fcp
                    ><labelled-link href="https://company.wizards.com/en/legal/fancontentpolicy">{{
                        $t("footer.fcp")
                    }}</labelled-link></template
                >
            </i18n-t>
        </section>
    </footer>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

footer {
    position: relative;

    margin-top: auto;

    background-color: map.get(c.$components, "footer", "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$components, "footer", "surface");

    &::before {
        position: absolute;
        inset: 0;
        z-index: map.get(z.$index, "background");

        border-top: map.get(s.$components, "footer", "border") solid transparent;

        background: linear-gradient(
                to right,
                map.get(c.$components, "footer", "border-from"),
                map.get(c.$components, "footer", "border-to")
            )
            border-box;

        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";
    }

    @include m.mqset(
        "padding",
        map.get(s.$components, "footer", "padding", "base"),
        map.get(s.$components, "footer", "padding", "portrait"),
        map.get(s.$components, "footer", "padding", "landscape"),
        map.get(s.$components, "footer", "padding", "desktop")
    );

    .inner {
        display: flex;
        flex-direction: column;

        max-width: map.get(s.$app, "max");
        margin: 0 auto;
        gap: 2ch;

        @include m.mq("portrait") {
            align-items: center;
            flex-direction: row;

            nav {
                margin-left: auto;
            }
        }

        &.gap {
            display: block;

            margin-top: 1lh;

            font-size: 0.9rem;

            a {
                display: inline-block;
            }
        }
    }

    a {
        display: inline-flex;
        align-items: center;

        img {
            width: 24px;
            height: auto;
        }
    }
}
</style>
