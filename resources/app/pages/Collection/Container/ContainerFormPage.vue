<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";
import { useI18n } from "vue-i18n";
import ArtCropImage from "Components/Card/ArtCropImage.vue";
import CardSearch from "Components/Card/CardSearch/CardSearch.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import type { Container } from "Types/container";
const props = defineProps<{
    containerTypes: string[];
    nameMax: number;
    descriptionMax: number;
    customTypeMax: number;
    /** Present in edit mode; absent when creating a new container. */
    container?: Container;
}>();
const { t } = useI18n();
/**
 * Local refs for text inputs — initialized from the container prop in edit mode,
 * empty strings when creating. Using local refs (rather than `:value="prop"`)
 * prevents Inertia's precognitive re-renders from resetting in-progress edits.
 */
const nameValue = ref(props.container?.name ?? "");
const descriptionValue = ref(props.container?.description ?? "");
const customTypeValue = ref(props.container?.custom_type ?? "");
const initialVisibility = props.container?.visibility ?? "private";
const visibilityOptions = [
    {
        value: "private",
        label: "form.fields.container.visibility_private",
        checked: initialVisibility === "private",
        icon: "visibility-off"
    },
    {
        value: "public",
        label: "form.fields.container.visibility_public",
        checked: initialVisibility === "public",
        icon: "visibility-on"
    }
];
const visibility = ref(initialVisibility);
/** True when a container prop is present, i.e. the form is in edit mode. */
const isEdit = computed(() => !!props.container);
/** Form action URL — points to the edit endpoint in edit mode, create endpoint otherwise. */
const formAction = computed(() => (isEdit.value ? `/containers/${props.container!.id}` : "/containers/new"));
/** HTTP method — PATCH for edit, POST for create. */
const formMethod = computed(() => (isEdit.value ? "patch" : "post"));
/** ContainerType options formatted for MonoSelect: `{ value, label }` pairs with translated labels. */
const types = computed(() => props.containerTypes.map(value => ({ value, label: t(`enums.container_type.${value}`) })));
/** Currently selected binder type. Initialized from the container prop in edit mode. */
const selectedType = ref(props.container?.type ?? "");
/**
 * Called when the type select changes. Updates selectedType and re-triggers
 * precognitive validation for the container_type field.
 *
 * @param value - The newly selected type value.
 * @param validate - The precognitive validate callback from the Form slot.
 */
const onTypeChange = (value: string, validate: (field: string) => void) => {
    selectedType.value = value;
    nextTick(() => validate("container_type"));
};
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.collection.link", href: "/collection", icon: "deck" },
    { labelKey: "pages.containers.link", href: "/containers", icon: "storage" },
    {
        label: isEdit.value ? t("pages.edit_container.title") : t("pages.new_container.title")
    }
]);
</script>

<template>
    <Head
        ><title>{{ isEdit ? $t("pages.edit_container.title") : $t("pages.new_container.title") }}</title></Head
    >
    <headline>
        <icon :name="isEdit ? 'storage' : 'add'" :size="3" />
        {{ isEdit ? $t("pages.edit_container.title") : $t("pages.new_container.title") }}
    </headline>
    <Form
        :method="formMethod"
        :action="formAction"
        class="form"
        #default="{ errors, processing, validating, valid, validate }"
    >
        <form-legend :items="[{ slot: 'required', icon: 'info' }]">
            <template #required>
                <i18n-t keypath="form.legend.required" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>
        <form-group
            for-id="container_name"
            :label="$t('form.fields.container.name')"
            :error="errors.container_name ?? ''"
            :invalid="!!errors?.container_name"
            :validated="valid('container_name')"
            :validating="validating"
            :required="true"
            addon-icon="container-name"
        >
            <input
                type="text"
                name="container_name"
                id="container_name"
                class="form-input"
                :maxlength="props.nameMax"
                v-model="nameValue"
                @change="validate('container_name')"
            />
        </form-group>
        <form-group :label="$t('form.fields.container.visibility')" :required="true">
            <radio-button-group
                name="container_visibility"
                :radio-buttons="visibilityOptions"
                @change="visibility = ($event.target as HTMLInputElement).value"
            />
        </form-group>
        <form-group
            :label="$t('form.fields.container.type')"
            :error="errors.container_type ?? ''"
            :invalid="!!errors?.container_type"
            :validated="valid('container_type')"
            :validating="validating"
            :required="true"
        >
            <mono-select
                :options="types"
                :selected="selectedType"
                @change="onTypeChange($event, validate)"
                addon-icon="storage"
                max="100%"
            />
            <input type="hidden" name="container_type" :value="selectedType" />
        </form-group>
        <form-group
            v-if="selectedType === 'other'"
            for-id="container_type_other"
            :label="$t('form.fields.container.other_type')"
            :error="errors.container_type_other ?? ''"
            :invalid="!!errors?.container_type_other"
            :validated="valid('container_type_other')"
            :validating="validating"
            :required="true"
            addon-icon="storage"
        >
            <input
                type="text"
                name="container_type_other"
                id="container_type_other"
                class="form-input"
                :maxlength="props.customTypeMax"
                v-model="customTypeValue"
                @change="validate('container_type_other')"
            />
        </form-group>
        <form-group
            for-id="container_description"
            :label="$t('form.fields.container.description')"
            :error="errors.container_description ?? ''"
            :invalid="!!errors?.container_description"
            :validated="valid('container_description')"
            :validating="validating"
            :required="false"
        >
            <div class="form-input__textarea-addon"><icon name="text" /></div>
            <div class="form-input form-input__textarea">
                <textarea
                    name="container_description"
                    id="container_description"
                    :maxlength="props.descriptionMax"
                    v-model="descriptionValue"
                    @change="validate('container_description')"
                />
            </div>
        </form-group>
        <card-search
            ref-id="container_image"
            endpoint="/api/art-crop"
            label="form.fields.container.image"
            placeholder="card.search.placeholder.art_crop"
            search-icon="image-search"
            selected-icon="container-image"
            :initial-card="container?.defaultCard ?? null"
        >
            <template #result="{ card }">
                <art-crop-image :card="card" interactive />
            </template>
            <template #selected="{ card }">
                <art-crop-image :card="card" />
            </template>
        </card-search>
        <form-group>
            <button type="submit" class="btn-primary" :disabled="processing">
                <icon name="save" />
                {{ isEdit ? $t("pages.edit_container.submit") : $t("pages.new_container.submit") }}
                <loading-spinner v-if="processing" :size="2" />
            </button>
        </form-group>
    </Form>
</template>

