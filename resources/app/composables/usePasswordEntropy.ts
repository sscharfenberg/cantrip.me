import { type Ref, ref } from "vue";

/**
 * Composable for real-time password entropy checking.
 *
 * Provides a reactive password ref, a debounced handler that posts the
 * current value to the server-side zxcvbn endpoint, and a reactive score
 * ref (0-4) updated with each response.
 */
export function usePasswordEntropy(): {
    password: Ref<string>;
    score: Ref<number | null>;
    onPasswordChange: () => void;
    reset: () => void;
} {
    const password = ref("");
    const score = ref<number | null>(null);

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let maxWaitTimer: ReturnType<typeof setTimeout> | null = null;

    const checkEntropy = () => {
        // Both timers race to get here, so whichever wins has to disarm the
        // other. Without clearing the debounce, a max-wait firing mid-typing
        // left it armed and a duplicate request went out 750ms later.
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = null;
        if (maxWaitTimer) {
            clearTimeout(maxWaitTimer);
            maxWaitTimer = null;
        }
        if (!password.value.length) return;
        fetch("/api/auth/entropy", {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ p: password.value })
        })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(data => {
                score.value = data.score;
            })
            .catch(error => {
                console.error(error);
            });
    };

    const onPasswordChange = () => {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(checkEntropy, 750);
        if (!maxWaitTimer) {
            maxWaitTimer = setTimeout(checkEntropy, 5000);
        }
    };

    const reset = () => {
        password.value = "";
        score.value = null;
    };

    return { password, score, onPasswordChange, reset };
}
