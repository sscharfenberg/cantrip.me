<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import StickyNav from "Components/UI/StickyNav.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import DashboardCollectionIntegration from "./DashboardCollectionIntegration.vue";
import DashboardDeckSort from "./DashboardDeckSort.vue";
import DashboardDeckView from "./DashboardDeckView.vue";
import DashboardPassword from "./DashboardPassword.vue";
import DashboardProfile from "./DashboardProfile.vue";
import DeleteAccount from "./Delete/DeleteAccount.vue";
import TwoFactor from "./TwoFactor/TwoFactor.vue";
const { t } = useI18n();
const navItems = computed(() => [
    { id: "passwordSection", label: t("pages.dashboard.password.link") },
    { id: "profileSection", label: t("pages.dashboard.profile.link") },
    { id: "deckViewSection", label: t("pages.dashboard.deck_view.link") },
    { id: "deckSortSection", label: t("pages.dashboard.deck_sort.link") },
    { id: "collectionIntegrationSection", label: t("pages.dashboard.collection_integration.link") },
    { id: "2faSection", label: t("pages.dashboard.two_factor.link") },
    { id: "deleteSection", label: t("pages.dashboard.deletion.link") }
]);
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.dashboard.link" }]);
</script>

<template>
    <Head
        ><title>{{ $t("pages.dashboard.title") }}</title></Head
    >
    <headline>
        <icon name="user-settings" :size="3" />
        {{ $t("pages.dashboard.title") }}
    </headline>
    <sticky-nav :items="navItems" />
    <dashboard-password />
    <dashboard-profile />
    <dashboard-deck-view />
    <dashboard-deck-sort />
    <dashboard-collection-integration />
    <two-factor />
    <delete-account />
</template>
