<script setup lang="ts">
/******************************************************************************
 * Master switch for the collection-integration feature.
 *
 * Off → every deck for this user resolves to mode A (no collection UI),
 * regardless of how many card stacks they own. Default is on.
 *****************************************************************************/
import { Form, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Switch from "Components/Form/Switch.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

/** Current user default pulled from the Inertia shared `auth.user` prop. */
const userDefault = (usePage().props.auth.user?.collection_integration_enabled ?? true) as boolean;
const enabled = ref<boolean>(userDefault);
</script>

<template>
    <headline :size="3" anchor-id="collectionIntegrationSection">
        <icon name="collection" />
        {{ $t("pages.dashboard.collection_integration.headline") }}
    </headline>
    <Form
        action="/collection-integration"
        method="post"
        class="form"
        :options="{ preserveScroll: true }"
        #default="{ processing }"
    >
        <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
            <template #intro>{{ $t("pages.dashboard.collection_integration.intro") }}</template>
        </form-legend>
        <form-group
            for-id="collection_integration_enabled_switch"
            :label="$t('pages.dashboard.collection_integration.toggle_label')"
        >
            <template #addon>
                <Switch
                    ref-id="collection_integration_enabled_switch"
                    :label="$t('pages.dashboard.collection_integration.toggle_label')"
                    :checked-initially="enabled"
                    @change="enabled = $event"
                />
            </template>
            <template #text>
                {{ $t("pages.dashboard.collection_integration.help") }}
            </template>
        </form-group>
        <input type="hidden" name="collection_integration_enabled" :value="enabled ? '1' : '0'" />
        <form-group>
            <button type="submit" class="btn-default" :disabled="processing">
                <icon name="save" />
                {{ $t("pages.dashboard.collection_integration.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
    </Form>
</template>

<style scoped lang="scss">
.form {
    margin: 1em 0;
}
</style>
