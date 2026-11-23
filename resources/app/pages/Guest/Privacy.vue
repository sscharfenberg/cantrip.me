<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.privacy.link" }]);
</script>

<template>
    <Head
        ><title>{{ $t("pages.privacy.title") }}</title></Head
    >
    <headline>
        <icon name="privacy" :size="3" />
        {{ $t("pages.privacy.title") }}
    </headline>
    <headline :size="3">{{ $t("pages.privacy.preamble") }}</headline>
    <paragraph>{{ $t("pages.privacy.preamble_text") }}</paragraph>
    <paragraph>{{ $t("pages.privacy.gender_note") }}</paragraph>
    <paragraph>{{ $t("pages.privacy.last_updated") }}</paragraph>
    <headline :size="3">{{ $t("pages.privacy.controller") }}</headline>
    <paragraph>
        Sven Scharfenberg<br />
        Blaue Str. 9<br />
        21726 Oldendorf
    </paragraph>
    <paragraph>
        {{ $t("pages.privacy.email_label") }}
        <labelled-link href="mailto:admin@cantrip.me">admin@cantrip.me</labelled-link>
    </paragraph>
    <headline :size="3">{{ $t("pages.privacy.processing_overview") }}</headline>
    <paragraph>{{ $t("pages.privacy.processing_overview_text") }}</paragraph>
    <headline :size="4">{{ $t("pages.privacy.data_types") }}</headline>
    <ul class="list">
        <li>{{ $t("pages.privacy.data_types_meta") }}</li>
        <li>{{ $t("pages.privacy.data_types_log") }}</li>
    </ul>
    <headline :size="4">{{ $t("pages.privacy.affected_persons") }}</headline>
    <ul class="list">
        <li>{{ $t("pages.privacy.affected_persons_users") }}</li>
        <li>{{ $t("pages.privacy.affected_persons_third") }}</li>
    </ul>
    <headline :size="4">{{ $t("pages.privacy.processing_purposes") }}</headline>
    <ul class="list">
        <li>{{ $t("pages.privacy.processing_purposes_security") }}</li>
        <li>{{ $t("pages.privacy.processing_purposes_provision") }}</li>
    </ul>
    <headline :size="3">{{ $t("pages.privacy.legal_bases") }}</headline>
    <paragraph>
        <strong>{{ $t("pages.privacy.legal_bases_intro") }} </strong>{{ $t("pages.privacy.legal_bases_text") }}
    </paragraph>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.legal_consent_title") }}</strong> -
            {{ $t("pages.privacy.legal_consent_text") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.legal_obligation_title") }}</strong> -
            {{ $t("pages.privacy.legal_obligation_text") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.legal_interest_title") }}</strong> -
            {{ $t("pages.privacy.legal_interest_text") }}
        </li>
    </ul>
    <paragraph>
        <strong>{{ $t("pages.privacy.national_regulations_title") }} </strong>{{
            $t("pages.privacy.national_regulations_text")
        }}
    </paragraph>
    <headline :size="3">{{ $t("pages.privacy.security_measures") }}</headline>
    <paragraph>{{ $t("pages.privacy.security_measures_text_1") }}</paragraph>
    <paragraph>{{ $t("pages.privacy.security_measures_text_2") }}</paragraph>
    <paragraph>{{ $t("pages.privacy.security_tls") }}</paragraph>
    <headline :size="3">{{ $t("pages.privacy.hosting") }}</headline>
    <paragraph>{{ $t("pages.privacy.hosting_text") }}</paragraph>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.data_types_label") }}</strong> {{ $t("pages.privacy.hosting_data_types") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.affected_label") }}</strong> {{ $t("pages.privacy.hosting_affected") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.purposes_label") }}</strong> {{ $t("pages.privacy.hosting_purposes") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.retention_label") }}</strong> {{ $t("pages.privacy.hosting_retention") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong> {{ $t("pages.privacy.hosting_legal") }}
        </li>
    </ul>
    <headline :size="4">{{ $t("pages.privacy.hosting_notes") }}</headline>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.hosting_own_title") }} </strong>{{ $t("pages.privacy.hosting_own_text") }}
            <span
                ><strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
                {{ $t("pages.privacy.legal_basis_interest") }}</span
            >
        </li>
        <li>
            <strong>{{ $t("pages.privacy.hosting_logs_title") }} </strong>{{ $t("pages.privacy.hosting_logs_text") }}
            <span
                ><strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
                {{ $t("pages.privacy.legal_basis_interest") }} </span
            ><strong>{{ $t("pages.privacy.hosting_logs_deletion_title") }}</strong>
            {{ $t("pages.privacy.hosting_logs_deletion_text") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.backblaze_title") }} </strong>{{ $t("pages.privacy.backblaze_text") }}
            <strong>{{ $t("pages.privacy.service_provider") }}</strong> Backblaze, Inc., 2261 Market Street STE 81006,
            San Francisco, CA 94114, USA;<br />
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.legal_basis_interest") }}
            <br />
            <strong>{{ $t("pages.privacy.website_label") }}</strong>
            <labelled-link href="https://www.backblaze.com">https://www.backblaze.com</labelled-link><br />
            <strong>{{ $t("pages.privacy.privacy_policy_label") }}</strong>
            <labelled-link href="https://www.backblaze.com/company/policy/privacy"
                >https://www.backblaze.com/company/policy/privacy</labelled-link
            ><br />
            <strong>{{ $t("pages.privacy.dpa_label") }}</strong>
            <labelled-link href="https://www.backblaze.com/company/policy/dpa-module-2"
                >https://www.backblaze.com/company/policy/dpa-module-2</labelled-link
            >
        </li>
        <li>
            <strong>{{ $t("pages.privacy.cloudflare_title") }} </strong>{{ $t("pages.privacy.cloudflare_text") }}
            <strong>{{ $t("pages.privacy.service_provider") }}</strong> Cloudflare, Inc., 101 Townsend St, San
            Francisco, CA 94107, USA;<br />
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.legal_basis_interest") }}
            <br />
            <strong>{{ $t("pages.privacy.website_label") }}</strong>
            <labelled-link href="https://www.cloudflare.com">https://www.cloudflare.com</labelled-link><br />
            <strong>{{ $t("pages.privacy.privacy_policy_label") }}</strong>
            <labelled-link href="https://www.cloudflare.com/privacypolicy/"
                >https://www.cloudflare.com/privacypolicy/</labelled-link
            ><br />
            <strong>{{ $t("pages.privacy.dpa_label") }}</strong>
            <labelled-link href="https://www.cloudflare.com/cloudflare-customer-dpa/"
                >https://www.cloudflare.com/cloudflare-customer-dpa/</labelled-link
            >
        </li>
    </ul>
    <headline :size="3">{{ $t("pages.privacy.cookies") }}</headline>
    <paragraph>{{ $t("pages.privacy.cookies_text") }}</paragraph>
    <paragraph>
        <strong>{{ $t("pages.privacy.cookies_legal_title") }} </strong>{{ $t("pages.privacy.cookies_legal_text") }}
    </paragraph>
    <paragraph>
        <strong>{{ $t("pages.privacy.cookies_duration_title") }}&nbsp;</strong
        >{{ $t("pages.privacy.cookies_duration_text") }}
    </paragraph>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.cookies_temp_title") }}</strong>
            {{ $t("pages.privacy.cookies_temp_text") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.cookies_perm_title") }}</strong>
            {{ $t("pages.privacy.cookies_perm_text") }}
        </li>
    </ul>
    <paragraph>
        <strong>{{ $t("pages.privacy.cookies_optout_title") }}&nbsp;</strong
        >{{ $t("pages.privacy.cookies_optout_text") }}
    </paragraph>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.data_types_label") }}</strong> {{ $t("pages.privacy.cookies_data_types") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.affected_label") }}</strong> {{ $t("pages.privacy.cookies_affected") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong> {{ $t("pages.privacy.legal_basis_interest") }}
        </li>
    </ul>
    <headline :size="3">{{ $t("pages.privacy.registration") }}</headline>
    <paragraph>{{ $t("pages.privacy.registration_text") }}</paragraph>
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.retention_label") }}</strong> {{ $t("pages.privacy.registration_retention") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_legal") }}
        </li>
    </ul>
    <paragraph
        ><strong>{{ $t("pages.privacy.registration_notes") }}</strong></paragraph
    >
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.registration_pseudonym_title") }} </strong>{{
                $t("pages.privacy.registration_pseudonym_text")
            }}
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_pseudonym_legal") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.registration_profiles_title") }} </strong>{{
                $t("pages.privacy.registration_profiles_text")
            }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.registration_2fa_title") }} </strong>{{
                $t("pages.privacy.registration_2fa_text")
            }}
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_2fa_legal") }}
        </li>
        <li>
            <strong>{{ $t("pages.privacy.mailtrap_title") }} </strong>{{ $t("pages.privacy.mailtrap_text") }}
            <strong>{{ $t("pages.privacy.service_provider") }}</strong> Railsware Products Studio LLC, 117 E Colorado
            Blvd, Suite 600, Office 650, Pasadena, CA 91105, USA;<br />
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_legal") }}
            <br />
            <strong>{{ $t("pages.privacy.website_label") }}</strong>
            <labelled-link href="https://mailtrap.io">https://mailtrap.io</labelled-link><br />
            <strong>{{ $t("pages.privacy.privacy_policy_label") }}</strong>
            <labelled-link href="https://mailtrap.io/privacy/">https://mailtrap.io/privacy/</labelled-link><br />
            <strong>{{ $t("pages.privacy.dpa_label") }}</strong>
            <labelled-link href="https://mailtrap.io/dpa/">https://mailtrap.io/dpa/</labelled-link>
        </li>
        <li>
            <strong>{{ $t("pages.privacy.registration_noretention_title") }} </strong>{{
                $t("pages.privacy.registration_noretention_text")
            }}
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_noretention_legal") }}
        </li>
    </ul>
    <paragraph
        ><strong>{{ $t("pages.privacy.registration_protection_notes") }}</strong></paragraph
    >
    <ul class="list">
        <li>
            <strong>{{ $t("pages.privacy.registration_protection_title") }} </strong>{{
                $t("pages.privacy.registration_protection_text")
            }}
            <strong>{{ $t("pages.privacy.legal_basis_label") }}</strong>
            {{ $t("pages.privacy.registration_protection_legal") }}
        </li>
    </ul>
    <headline :size="3">{{ $t("pages.privacy.changes") }}</headline>
    <paragraph>{{ $t("pages.privacy.changes_text") }}</paragraph>
    <paragraph>{{ $t("pages.privacy.changes_addresses") }}</paragraph>
    <paragraph>
        <labelled-link
            href="https://datenschutz-generator.de/"
            title="Rechtstext von Dr. Schwenke - für weitere Informationen bitte anklicken."
            >{{ $t("pages.privacy.generated_by") }}</labelled-link
        >
    </paragraph>
</template>
