<script setup lang="ts">
import { computed, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth.ts";
const {
    handleRegenerateRecoveryCodes,
    handleShowRecoveryCodes,
    isRecoveryCodesVisible,
    processing,
    recoveryCodesList,
    requiresPasswordConfirmation,
    validationErrors
} = useTwoFactorAuth();
/** Recovery codes joined by newline for display in the readonly textarea. */
const recoveryCodesString = computed(() => recoveryCodesList.value.join("\n"));
/** Password input bound to the confirmation field (only shown when the session requires re-confirmation). */
const password = ref("");
/** Toggles the password field between `text` and `password` type for visibility. */
const showPassword = ref(false);
/** FormLegend items — includes the "required" hint only when password confirmation is active. */
const legendItems = computed(() => {
    const items = [{ slot: "intro", icon: "info" }];
    if (requiresPasswordConfirmation.value) items.push({ slot: "required", icon: "info" });
    return items;
});
/**
 * Dispatches the form submission to the correct handler based on the submitter button's value.
 * - `"show"` reveals the existing recovery codes.
 * - `"regenerate"` generates a fresh set and displays them.
 *
 * @param e - The native submit event, used to identify which button triggered the submission.
 */
const onSubmit = (e: SubmitEvent) => {
    const action = (e.submitter as HTMLButtonElement | null)?.value;
    if (action === "show") handleShowRecoveryCodes(password.value);
    else if (action === "regenerate") handleRegenerateRecoveryCodes(password.value);
};
</script>

<template>
    <headline :size="4">{{ $t("pages.dashboard.two_factor.recovery_codes.headline") }}</headline>
    <form class="form" @submit.prevent="onSubmit">
        <form-legend :items="legendItems">
            <template #intro>{{ $t("pages.dashboard.two_factor.recovery_codes.explanation") }}</template>
            <template #required>
                <i18n-t keypath="form.legend.required" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>
        <form-group
            v-if="requiresPasswordConfirmation && !isRecoveryCodesVisible"
            for-id="recovery-codes-password"
            :label="$t('form.fields.password')"
            :error="validationErrors.password"
            :invalid="!!validationErrors.password"
            :required="true"
            addon-icon="key"
        >
            <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                id="recovery-codes-password"
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
        <form-group v-if="!isRecoveryCodesVisible">
            <button type="submit" name="action" value="show" class="btn-default" :disabled="processing">
                <icon name="visibility-on" />
                {{ $t("pages.dashboard.two_factor.recovery_codes.show") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
        <template v-if="isRecoveryCodesVisible">
            <form-group :label="$t('pages.dashboard.two_factor.recovery_codes.label')">
                <div class="form-input__textarea-addon"><icon name="key" /></div>
                <div class="form-input form-input__textarea">
                    <textarea :value="recoveryCodesString" readonly />
                </div>
            </form-group>
            <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
                <template #intro>{{ $t("pages.dashboard.two_factor.regenerate_codes.explanation") }}</template>
            </form-legend>
            <form-group>
                <button type="submit" name="action" value="regenerate" class="btn-default" :disabled="processing">
                    {{ $t("pages.dashboard.two_factor.regenerate_codes.submit") }}
                </button>
            </form-group>
        </template>
    </form>
</template>

<style lang="scss" scoped>
.form {
    margin-bottom: 1lh;
}
</style>
