<?php

namespace App\Http\Requests\Decks;

use App\Enums\ContainerVisibility;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

class ShowDeckRequest extends FormRequest
{
    /**
     * Public decks are visible to anyone with the link; private decks are
     * only visible to the owner. The route is mounted outside the auth
     * middleware group, so unauthenticated requests must be tolerated when
     * the deck is public — `$this->user()` may be null.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        if (! $deck instanceof Deck) {
            return false;
        }

        return $deck->visibility === ContainerVisibility::Public
            || $deck->user_id === $this->user()?->id;
    }

    /**
     * Throw 404 instead of the default 403 — we don't want non-owners
     * to be able to enumerate the existence of private decks. Mirrors
     * the same behaviour {@see ShowContainerRequest} uses for containers.
     */
    protected function failedAuthorization(): never
    {
        abort(404);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
