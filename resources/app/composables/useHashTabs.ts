import { router } from "@inertiajs/vue3";
import type { MaybeRefOrGetter, Ref } from "vue";
import { onBeforeUnmount, onMounted, ref, toValue } from "vue";

/** Return type of {@link useHashTabs}. */
export type UseHashTabsReturn = {
    /** Id of the open tab. */
    active: Ref<string>;
    /** Open a tab and reflect it in the URL hash. Unknown ids are ignored. */
    select: (id: string) => void;
};

/**
 * A set of tabs whose open tab lives in the URL hash, so `/b+r#legacy`
 * opens the Legacy tab and a copied address re-opens the same one.
 *
 * The initial tab is the hash when it names one of `ids` (ignoring case),
 * else the first id. Selecting a tab rewrites the hash through Inertia's
 * client-side `router.replace` rather than `history.replaceState`: Inertia
 * keeps its own copy of the page URL and writes it back to the address bar
 * on its next history replace (a back/forward reload, for one), which would
 * silently restore the hash the page was loaded with. A replace adds no
 * history entry, and `preserveState` / `preserveScroll` keep the page as it
 * is. Editing the hash by hand fires `hashchange`, which opens that tab too.
 *
 * @param ids - Tab ids in display order — plain lowercase ASCII, as the hash is matched without decoding.
 */
export function useHashTabs(ids: MaybeRefOrGetter<string[]>): UseHashTabsReturn {
    const fromHash = (): string | null => {
        const hash = window.location.hash.slice(1).toLowerCase();
        return toValue(ids).includes(hash) ? hash : null;
    };

    const active = ref(fromHash() ?? toValue(ids)[0] ?? "");

    const select = (id: string): void => {
        if (!toValue(ids).includes(id)) return;
        active.value = id;
        router.replace({
            url: `${window.location.pathname}${window.location.search}#${id}`,
            preserveState: true,
            preserveScroll: true
        });
    };

    const onHashChange = (): void => {
        const id = fromHash();
        if (id !== null) active.value = id;
    };
    onMounted(() => window.addEventListener("hashchange", onHashChange));
    onBeforeUnmount(() => window.removeEventListener("hashchange", onHashChange));

    return { active, select };
}
