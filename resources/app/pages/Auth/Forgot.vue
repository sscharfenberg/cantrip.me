<script setup lang="ts">
import { Form, Head, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import NarrowLayout from "Components/Layout/NarrowLayout.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
defineOptions({ layout: NarrowLayout });
const page = usePage();
const features = computed(() => page.props.features);
const types = [
    { value: "password", label: "pages.forgot.type.password", checked: true, icon: "key" },
    { value: "name", label: "pages.forgot.type.username", checked: false, icon: "account" }
];
const type = ref(types.find(type => type.checked)?.value ?? "password");
/** Switch between the password-reset and username-reminder request types. */
const onChange = (ev: { target: { value: string } }) => {
    type.value = ev.target.value;
};
</script>

<template>
    <Head
        ><title>{{ $t("pages.forgot.title") }}</title></Head
    >
    <headline>
        <icon name="security" :size="3" />
        {{ $t("pages.forgot.title") }}
    </headline>
    <Form
        action="/forgot"
        method="post"
        class="form"
        #default="{ errors, valid, invalid, validating, validate, processing }"
    >
        <form-legend
            :items="[
                { slot: 'intro', icon: 'info' },
                { slot: 'required', icon: 'info' }
            ]"
        >
            <template #intro>{{ $t("pages.forgot.intro") }}</template>
            <template #required>
                <i18n-t keypath="form.legend.required" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>
        <form-group for-id="type_password">
            <radio-button-group name="type" :radio-buttons="types" @change="onChange" />
        </form-group>
        <form-group
            v-if="type === 'password'"
            for-id="name"
            :label="$t('form.fields.username')"
            :error="errors.name"
            :invalid="invalid('name')"
            :validated="valid('name')"
            :validating="validating"
            addon-icon="register"
            :required="true"
        >
            <input type="text" name="name" id="name" maxlength="80" @change="validate('name')" class="form-input" />
        </form-group>
        <form-group
            for-id="email"
            :label="$t('form.fields.email')"
            :error="errors.email"
            :invalid="invalid('email')"
            :validated="valid('email')"
            :validating="validating"
            addon-icon="mail"
            :required="true"
        >
            <input
                type="email"
                name="email"
                id="email"
                maxlength="255"
                @change="validate('email')"
                class="form-input"
            />
        </form-group>
        <form-group>
            <button type="submit" class="btn-primary" :disabled="processing">
                <icon name="save" />
                {{ $t("pages.forgot.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
        <form-group>
            <link-group v-if="features.emailVerification" :label="$t('pages.login.nav_label')">
                <labelled-link href="/resend-verification">{{ $t("pages.resend_verification.link") }}</labelled-link>
            </link-group>
        </form-group>
    </Form>
</template>
