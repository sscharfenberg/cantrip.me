<script setup lang="ts">
import { computed } from "vue";
import FormLegend from "Components/Form/FormLegend.vue";
const props = withDefaults(
    defineProps<{
        /**
         * When true, render two extra hints describing the page-level
         * keyboard shortcuts (+/- to change amount, Enter to save and
         * add more). Only the "Add cards to collection" page enables
         * this — every other caller leaves it off.
         */
        keyboardShortcuts?: boolean;
    }>(),
    {
        keyboardShortcuts: false
    }
);
const items = computed(() => [
    { slot: "general", icon: "info" },
    { slot: "set", icon: "collection" },
    { slot: "number", icon: "star" },
    { slot: "combined", icon: "deck" },
    ...(props.keyboardShortcuts
        ? [
              { slot: "amount", icon: "add" },
              { slot: "save_add", icon: "save" },
              { slot: "recall", icon: "key" }
          ]
        : [])
]);
</script>

<template>
    <form-legend :items="items">
        <template #general>
            <i18n-t keypath="card.search.tips.general" scope="global">
                <template #query
                    ><strong>{{ $t("card.search.tips.query") }}</strong></template
                >
                <template #result
                    ><strong>{{ $t("card.search.tips.result") }}</strong></template
                >
            </i18n-t>
        </template>
        <template #set>
            <i18n-t keypath="card.search.tips.set" scope="global">
                <template #set_codes
                    ><strong>{{ $t("card.search.tips.set_codes") }}</strong></template
                >
                <template #set_example
                    ><strong>{{ $t("card.search.tips.set_example") }}</strong></template
                >
                <template #set_result
                    ><strong>{{ $t("card.search.tips.set_result") }}</strong></template
                >
            </i18n-t>
        </template>
        <template #number>
            <i18n-t keypath="card.search.tips.cn" scope="global">
                <template #cn_codes
                    ><strong>{{ $t("card.search.tips.cn_codes") }}</strong></template
                >
                <template #cn_example
                    ><strong>{{ $t("card.search.tips.cn_example") }}</strong></template
                >
                <template #cn_result
                    ><strong>{{ $t("card.search.tips.cn_result") }}</strong></template
                >
            </i18n-t>
        </template>
        <template #combined>
            <i18n-t keypath="card.search.tips.combined" scope="global">
                <template #combined_example
                    ><strong>{{ $t("card.search.tips.combined_example") }}</strong></template
                >
                <template #combined_result
                    ><strong>{{ $t("card.search.tips.combined_result") }}</strong></template
                >
            </i18n-t>
        </template>
        <template v-if="keyboardShortcuts" #amount>
            <i18n-t keypath="card.search.tips.amount" scope="global">
                <template #plus
                    ><strong>{{ $t("card.search.tips.plus") }}</strong></template
                >
                <template #minus
                    ><strong>{{ $t("card.search.tips.minus") }}</strong></template
                >
            </i18n-t>
        </template>
        <template v-if="keyboardShortcuts" #save_add>
            <i18n-t keypath="card.search.tips.save_add" scope="global">
                <template #enter
                    ><strong>{{ $t("card.search.tips.enter") }}</strong></template
                >
            </i18n-t>
        </template>
        <template v-if="keyboardShortcuts" #recall>
            <i18n-t keypath="card.search.tips.recall" scope="global">
                <template #tab_key
                    ><strong>{{ $t("card.search.tips.tab_key") }}</strong></template
                >
            </i18n-t>
        </template>
    </form-legend>
</template>
