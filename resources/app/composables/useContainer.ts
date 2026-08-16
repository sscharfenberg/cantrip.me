import { usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import type { ComputedRef, Ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "Composables/useToast";
import type { Container } from "Types/container";

export type UseContainerReturn = {
    localContainers: Ref<Container[]>;
    isSaving: Ref<boolean>;
    handleReorder: (reordered: Container[]) => void;
    activeTypes: Ref<Set<string>>;
    search: Ref<string>;
    usedTypes: ComputedRef<string[]>;
    filteredContainers: ComputedRef<Container[]>;
    toggleType: (type: string) => void;
    typeLabel: (type: string) => string;
};

/**
 * Manages container sort order, type filtering, and name search.
 *
 * Combines sort (optimistic drag-drop reorder with debounced PATCH) and
 * filter (client-side type + name search) into a single composable.
 *
 * The `containers` prop ref is watched so that Inertia page-prop updates
 * (e.g. after a delete) automatically sync into local state.
 *
 * @param containers - Reactive ref to the server-provided container array (Inertia prop).
 * @param containerTypes - Static array of known ContainerType enum values.
 * @returns Reactive sort/filter state and handler functions.
 */
export function useContainer(containers: Ref<Container[]>, containerTypes: string[]): UseContainerReturn {
    const page = usePage();
    const { t } = useI18n();
    const { addToast } = useToast();

    // ── Sort state ──────────────────────────────────────────────────────

    /** Local mutable copy of containers — updated on drag reorder without a full page reload. */
    const localContainers = ref<Container[]>([...containers.value]);

    /** True while a sort request is debouncing or in flight. */
    const isSaving = ref(false);

    /** Timer handle for debouncing sort PATCHes — reset on every new reorder. */
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;

    /** AbortController for the most recent in-flight sort PATCH. */
    let abortController: AbortController | null = null;

    /**
     * Sync local state when Inertia refreshes the page props (e.g. after a
     * create or delete elsewhere on the page).
     *
     * A container whose type the filter has never offered before is switched
     * on, matching the "everything visible" state the filter starts in.
     * Without that, creating the first deckbox would add its chip to the filter
     * bar while the container itself stayed hidden until a full reload — and a
     * type the user has deliberately switched off stays off, because it is
     * already known.
     */
    watch(containers, fresh => {
        localContainers.value = [...fresh];

        const revealed = new Set(activeTypes.value);
        for (const container of fresh) {
            const type = effectiveType(container);
            if (!knownTypes.has(type)) {
                knownTypes.add(type);
                revealed.add(type);
            }
        }
        activeTypes.value = revealed;
    });

    /**
     * Handles a drag-drop reorder emitted by ContainersResultList.
     *
     * `reordered` is only the currently visible (filtered) subset in its new order.
     * The filtered items are slotted back into the positions they occupied in
     * `localContainers`, so hidden items keep their relative positions.
     */
    function handleReorder(reordered: Container[]) {
        const reorderedIds = new Set(reordered.map(c => c.id));
        const positions = localContainers.value.map((c, i) => (reorderedIds.has(c.id) ? i : -1)).filter(i => i !== -1);
        const newList = [...localContainers.value];
        positions.forEach((pos, i) => {
            newList[pos] = reordered[i]!;
        });
        localContainers.value = newList;

        isSaving.value = true;
        if (debounceTimer !== null) clearTimeout(debounceTimer);

        debounceTimer = setTimeout(() => {
            abortController?.abort();
            abortController = new AbortController();

            fetch("/containers/sort", {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": page.props.csrfToken as string
                },
                body: JSON.stringify({ order: newList.map(c => c.id) }),
                signal: abortController.signal
            })
                .then(res => res.json())
                .then((data: { ok?: boolean }) => {
                    isSaving.value = false;
                    if (!data.ok) addToast(t("pages.containers.sort_error"), "error");
                })
                .catch((err: Error) => {
                    if (err.name !== "AbortError") {
                        isSaving.value = false;
                        addToast(t("pages.containers.sort_error"), "error");
                    }
                });
        }, 500);
    }

    // ── Filter state ────────────────────────────────────────────────────

    /**
     * Returns the display/filter key for a container.
     * For "other" containers, returns the custom_type value instead of "other"
     * so custom types can be filtered and labeled individually.
     */
    function effectiveType(c: Container): string {
        return c.type === "other" && c.custom_type ? c.custom_type : c.type;
    }

    /**
     * Returns the translated label for a standard binder type,
     * or the raw string for user-defined custom types.
     */
    function typeLabel(type: string): string {
        return containerTypes.includes(type) ? t(`enums.container_type.${type}`) : type;
    }

    /** Currently selected type filter keys. All types selected by default = show all. */
    const activeTypes = ref<Set<string>>(new Set(localContainers.value.map(effectiveType)));

    /**
     * Every type the filter has already offered the user. Distinct from
     * `activeTypes`: a type the user switched off is still known, and so is not
     * silently switched back on when the page props refresh.
     */
    const knownTypes = new Set<string>(activeTypes.value);

    /** Current name search string (raw, lowercasing happens in filteredContainers). */
    const search = ref("");

    /** Unique effective types present in the current container list, used to build the type filter. */
    const usedTypes = computed(() => [...new Set(localContainers.value.map(effectiveType))]);

    /**
     * Containers after applying both the active type filter and the name search.
     */
    const filteredContainers = computed(() => {
        const needle = search.value.toLowerCase();
        return localContainers.value.filter(
            c => activeTypes.value.has(effectiveType(c)) && (!needle || c.name.toLowerCase().includes(needle))
        );
    });

    /**
     * Toggles a type in the active filter set.
     */
    function toggleType(type: string) {
        const next = new Set(activeTypes.value);
        if (next.has(type)) {
            next.delete(type);
        } else {
            next.add(type);
        }
        activeTypes.value = next;
    }

    return {
        localContainers,
        isSaving,
        handleReorder,
        activeTypes,
        search,
        usedTypes,
        filteredContainers,
        toggleType,
        typeLabel
    };
}
