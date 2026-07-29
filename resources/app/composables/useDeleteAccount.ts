import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import type { Ref } from "vue";

export type UseDeleteAccountReturn = {
    processing: Ref<boolean>;
    passwordError: Ref<string>;
    deleteAccount: (password: string) => Promise<void>;
};

/**
 * Composable that manages account deletion from the confirmation modal.
 *
 * Uses `fetch()` with JSON headers instead of `router.delete()` so a failed
 * password does not trigger a full Inertia visit on the dashboard behind the
 * modal — no scroll jump, no global errors bag pollution, and the error stays
 * strictly local to the modal. On success the backend returns a JSON
 * `{ redirect }` payload and we hand off to `router.visit()` to complete the
 * navigation in a single Inertia-aware step.
 */
export const useDeleteAccount = (): UseDeleteAccountReturn => {
    const page = usePage();
    const csrfToken = computed(() => page.props.csrfToken as string);

    /** True while a delete request is in-flight. */
    const processing = ref(false);
    /** First password validation message from the backend, empty when clean. */
    const passwordError = ref("");

    /**
     * Submit the delete-account request and handle the three outcomes:
     * 422 (wrong password) → surface the inline error, keep modal open,
     * success → follow the redirect via Inertia, everything else → reset
     * processing so the user can retry.
     *
     * @param password - Current user password used as the confirmation.
     */
    const deleteAccount = async (password: string): Promise<void> => {
        processing.value = true;
        passwordError.value = "";

        try {
            const response = await fetch("/user/delete", {
                method: "DELETE",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": csrfToken.value
                },
                body: JSON.stringify({ password })
            });

            if (response.status === 422) {
                const data = (await response.json().catch(() => ({}))) as {
                    errors?: Record<string, string[] | string>;
                };
                const raw = data.errors?.password;
                passwordError.value = Array.isArray(raw) ? String(raw[0] ?? "") : String(raw ?? "");
                return;
            }

            if (!response.ok) {
                return;
            }

            const data = (await response.json().catch(() => ({}))) as { redirect?: string };
            router.visit(data.redirect ?? "/");
        } finally {
            processing.value = false;
        }
    };

    return { processing, passwordError, deleteAccount };
};
