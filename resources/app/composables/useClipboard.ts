import type { Ref } from "vue";
import { ref } from "vue";

/** Return type of the {@link useClipboard} composable. */
export type UseClipboardReturn = {
    copied: Ref<boolean>;
    copy: (text: string) => Promise<void>;
};

/**
 * Composable that provides clipboard write access with cross-browser support.
 *
 * Attempts to use the modern `navigator.clipboard` API and falls back to the
 * legacy `document.execCommand('copy')` approach for older browsers and some
 * mobile environments where the Clipboard API is unavailable or restricted.
 *
 * `copied` is set to `true` on success and automatically resets after a short
 * delay so the caller can reflect temporary "copied!" feedback in the UI.
 */
export const useClipboard = (): UseClipboardReturn => {
    // Per-component — each consumer gets its own `copied` state so that
    // multiple copy buttons on the same page do not interfere with each other.
    const copied = ref(false);

    /**
     * Copy the given string to the system clipboard.
     *
     * Prefers the async Clipboard API when available. Falls back to creating
     * a temporary `<textarea>`, selecting its content, and executing the
     * legacy `execCommand('copy')` — a technique that works in most older
     * and restricted contexts including iOS/Android WebViews.
     *
     * On success, {@link copied} is set to `true` and automatically resets to
     * `false` after 2 seconds. Clipboard errors are swallowed silently because
     * they can be triggered by browser or OS permission policies outside our
     * control, and the UI should not break as a result.
     *
     * @param text - The string to place on the clipboard.
     */
    const copy = async (text: string): Promise<void> => {
        try {
            if (navigator.clipboard?.writeText) {
                // Modern async API — requires a secure context (HTTPS / localhost).
                await navigator.clipboard.writeText(text);
            } else {
                // Legacy fallback: append an off-screen textarea, select its
                // content, and trigger the browser's built-in copy command.
                const textarea = document.createElement("textarea");
                textarea.value = text;
                // Position off-screen so the element does not affect layout or
                // cause a visible flash.
                textarea.style.cssText = "position:fixed;top:-9999px;left:-9999px;opacity:0";
                document.body.appendChild(textarea);
                try {
                    textarea.focus();
                    textarea.select();
                    document.execCommand("copy");
                } finally {
                    // In a `finally` because an `execCommand` that throws would
                    // otherwise leave the scratch element in the document for
                    // the rest of the session.
                    textarea.remove();
                }
            }

            copied.value = true;
            setTimeout(() => {
                copied.value = false;
            }, 2000);
        } catch {
            // Silently swallow — clipboard access can be denied by the browser
            // or the OS, especially on mobile. The UI remains unchanged.
        }
    };

    return { copied, copy };
};
