<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.imprint.link" }]);
</script>

<template>
    <Head
        ><title>{{ $t("pages.imprint.title") }}</title></Head
    >
    <headline>
        <icon name="docs" :size="3" />
        {{ $t("pages.imprint.title") }}
    </headline>
    <headline :size="3">{{ $t("pages.imprint.legal_info") }}</headline>
    <paragraph>
        Sven Scharfenberg<br />
        Blaue Str. 9<br />
        21726 Oldendorf
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.represented_by") }}</strong><br />
        Sven Scharfenberg
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.contact") }}</strong><br />
        {{ $t("pages.imprint.phone") }} 04144-7774<br />
        {{ $t("pages.imprint.email") }}
        <labelled-link href="mailto:admin@cantrip.me">admin@cantrip.me</labelled-link>
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.responsible") }}</strong><br />
        Sven Scharfenberg<br />
        Blaue Str. 9<br />
        21726 Oldendorf
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.dispute_resolution") }}</strong><br />
        {{ $t("pages.imprint.dispute_resolution_text") }}
    </paragraph>
    <headline :size="3">{{ $t("pages.imprint.disclaimer") }}</headline>
    <paragraph>
        <strong>{{ $t("pages.imprint.liability_content") }}</strong><br />
        {{ $t("pages.imprint.liability_content_text") }}
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.liability_links") }}</strong><br />
        {{ $t("pages.imprint.liability_links_text") }}
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.imprint.copyright") }}</strong><br />
        {{ $t("pages.imprint.copyright_text") }}
    </paragraph>
    <paragraph>
        {{ $t("pages.imprint.generated_by") }}
        <labelled-link href="https://impressum-generator.de">impressum-generator.de</labelled-link>.
        {{ $t("pages.imprint.powered_by") }}
        <labelled-link href="https://www.kanzlei-hasselbach.de/">Franziska Hasselbach, Bonn</labelled-link>.
    </paragraph>
</template>
