<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack update endpoint: only the owner may modify
 * a stack. The form-field `container_id` (target container) is checked
 * separately via {@see CardStackService::resolveOwnedContainer} since
 * that's a form-data concern, not a route-resource one.
 */
class UpdateCardStackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cardStack = $this->route('cardStack');

        return $cardStack instanceof CardStack && $cardStack->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
