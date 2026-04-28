<?php

namespace App\Http\Requests\Collection;

use App\Enums\ContainerVisibility;
use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack preview JSON endpoint.
 *
 * The route is mounted outside the auth middleware group so card-preview
 * clicks on a public-container view work for non-owners (and unauthenticated
 * visitors). Owner sees their own stacks unconditionally; everyone else is
 * only granted access when the stack belongs to a container marked public.
 */
class ShowCardStackPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cardStack = $this->route('cardStack');
        if (! $cardStack instanceof CardStack) {
            return false;
        }

        if ($cardStack->user_id === $this->user()?->id) {
            return true;
        }

        $cardStack->loadMissing('container');

        return $cardStack->container?->visibility === ContainerVisibility::Public;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
