<script setup lang="ts">
import { Head, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import OTPInput from "Components/Form/OTPInput/OTPInput.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Switch from "Components/Form/Switch.vue";
import NarrowLayout from "Components/Layout/NarrowLayout.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useLogin } from "Composables/useLogin.ts";
defineOptions({ layout: NarrowLayout });
defineProps<{
    status?: string;
}>();
const page = usePage();
const features = computed(() => page.props.features);
const showPassword = ref(false);
const { errors, name, password, remember, requiresTwoFactor, recoveryCode, showRecoveryCode, processing, submit } =
    useLogin();
const legendItems = computed(() => {
    const items = [{ slot: "required", icon: "info" }];
    if (requiresTwoFactor.value) items.push({ slot: "twoFactor", icon: "security" });
    return items;
});
const codeTypes = [
    { value: "2fa", label: "pages.login.2fa.toggle.2fa", checked: !showRecoveryCode.value },
    { value: "recovery", label: "pages.login.2fa.toggle.recovery", checked: showRecoveryCode.value }
];
/** Flip between 2FA TOTP code and recovery code input based on the toggle. */
const onCodeTypeChange = (event: Event) => {
    const value = (event.target as HTMLInputElement | null)?.value;
    showRecoveryCode.value = value === "recovery";
};
</script>

<template>
    <Head
        ><title>{{ $t("pages.login.title") }}</title></Head
    >
    <headline>
        <icon name="key" :size="3" />
        {{ $t("pages.login.title") }}
    </headline>
    <form class="form" @submit.prevent="submit">
        <form-legend :items="legendItems">
            <template #required>
                <i18n-t keypath="form.legend.required" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
            <template #twoFactor>{{ $t("form.legend.2fa") }}</template>
        </form-legend>
        <form-group
            v-if="!requiresTwoFactor"
            for-id="name"
            :label="$t('form.fields.username')"
            :error="errors.name ?? ''"
            addon-icon="register"
            :required="true"
            :invalid="!!errors?.name"
        >
            <input v-model="name" type="text" name="name" id="name" class="form-input" />
        </form-group>
        <form-group
            v-if="!requiresTwoFactor"
            for-id="password"
            :label="$t('form.fields.password')"
            :error="errors.password ?? ''"
            :invalid="!!errors?.password"
            :required="true"
            addon-icon="key"
        >
            <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                id="password"
                class="form-input"
            />
            <template #button>
                <button
                    type="button"
                    @mousedown.prevent
                    @click="showPassword = !showPassword"
                    :aria-label="showPassword ? $t('components.password.hide') : $t('components.password.show')"
                    tabindex="-1"
                >
                    <icon :name="showPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPassword ? $t("components.password.hide") : $t("components.password.show") }}</span>
                </button>
            </template>
        </form-group>
        <form-group v-if="!requiresTwoFactor" for-id="remember" :label="$t('form.fields.remember')">
            <template #addon>
                <Switch
                    :label="$t('form.fields.remember')"
                    ref-id="remember"
                    value="true"
                    :checked-initially="true"
                    @change="remember = $event"
                />
            </template>
        </form-group>
        <form-group
            v-if="requiresTwoFactor"
            for-id="code"
            :label="showRecoveryCode ? $t('pages.login.2fa.recovery_code') : $t('pages.login.2fa.2fa_code')"
            :error="showRecoveryCode ? (errors.recovery_code ?? '') : (errors.code ?? '')"
            :invalid="showRecoveryCode ? !!errors.recovery_code : !!errors.code"
            :required="true"
        >
            <OTPInput
                v-if="!showRecoveryCode"
                id="code"
                v-model="recoveryCode"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="6"
                autofocus
                @complete="() => submit()"
            />
            <input
                v-else
                id="code"
                v-model="recoveryCode"
                type="text"
                name="code"
                class="form-input"
                autocomplete="one-time-code"
                autofocus
            />
        </form-group>
        <form-group v-if="requiresTwoFactor">
            <radio-button-group name="type" :radio-buttons="codeTypes" @change="onCodeTypeChange" />
        </form-group>
        <form-group>
            <button type="submit" class="btn-primary" :disabled="processing">
                <icon name="key" />
                {{ requiresTwoFactor ? $t("pages.login.2fa.verify") : $t("pages.login.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
        <form-group>
            <link-group :label="$t('pages.login.nav_label')">
                <labelled-link v-if="features.registration" href="/register">{{
                    $t("pages.register.link")
                }}</labelled-link>
                <labelled-link v-if="features.resetPasswords" href="/forgot">{{
                    $t("pages.forgot.link")
                }}</labelled-link>
                <labelled-link v-if="features.emailVerification" href="/resend-verification">{{
                    $t("pages.resend_verification.link")
                }}</labelled-link>
            </link-group>
        </form-group>
    </form>
</template>
