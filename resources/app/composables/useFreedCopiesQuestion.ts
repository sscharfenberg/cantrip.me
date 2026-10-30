import type { Ref } from "vue";
import { ref } from "vue";
import type { FreedCopies } from "Composables/useDeckCardActions";

/**
 * The server's 409 to a request that takes cards out of a deck while the
 * claimed copies it frees lie in the deck's deckbox — changing the command
 * zone, replacing or removing the companion. Unlike the deck card menu,
 * which knows its card, these name the cards that leave.
 */
export interface FreedCopiesQuestion extends FreedCopies {
    /** The cards that leave the deck, e.g. "Thrasios & Tymna". */
    name: string;
}

/** Return type of {@link useFreedCopiesQuestion}. */
export type UseFreedCopiesQuestionReturn = {
    /** The open question — render `DeckCardFreedCopiesModal` from it — or null. */
    question: Ref<FreedCopiesQuestion | null>;
    /** Answer the open question: a `move_to` value, or null to keep the cards in the deck. */
    answer: (moveTo: string | null) => void;
    /**
     * Send `request`; when it answers 409, ask where the cards go and send it
     * again with the answer as `move_to`. Resolves to the last response, or
     * to null when the question was cancelled.
     */
    send: (request: (moveTo?: string) => Promise<Response>) => Promise<Response | null>;
};

/**
 * "Where should the cards go?" for the requests that take cards out of a
 * deck outside the deck card menu, which asks the same through
 * `useDeckCardActions`' `placeFreedCopies`. State is per caller: each menu
 * or modal asks its own question.
 */
export function useFreedCopiesQuestion(): UseFreedCopiesQuestionReturn {
    const question = ref<FreedCopiesQuestion | null>(null);
    let resolveAnswer: ((moveTo: string | null) => void) | null = null;

    function answer(moveTo: string | null): void {
        question.value = null;
        resolveAnswer?.(moveTo);
        resolveAnswer = null;
    }

    async function send(request: (moveTo?: string) => Promise<Response>): Promise<Response | null> {
        const response = await request();
        if (response.status !== 409) return response;
        const freed = (await response.json().catch(() => null)) as FreedCopiesQuestion | null;
        if (freed === null) return null;
        const moveTo = await new Promise<string | null>(resolve => {
            resolveAnswer = resolve;
            question.value = { count: freed.count, container_id: freed.container_id, name: freed.name ?? "" };
        });
        return moveTo === null ? null : request(moveTo);
    }

    return { question, answer, send };
}
