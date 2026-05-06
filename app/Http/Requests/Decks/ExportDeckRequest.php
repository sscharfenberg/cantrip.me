<?php

namespace App\Http\Requests\Decks;

use App\Enums\ContainerVisibility;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the deck CSV export endpoint. Sibling of
 * {@see ShowDeckRequest}: public decks are exportable by anyone with the
 * link (including anonymous visitors), private decks are exportable only
 * by the owner. The pivot `Card Stack ID` column is blanked for
 * non-owners by the service so a public-deck export never leaks the
 * owner's collection ids.
 */
class ExportDeckRequest extends FormRequest
{
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
     * 404 on unauthorized — same enumeration-prevention rationale as
     * {@see ShowDeckRequest}.
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
