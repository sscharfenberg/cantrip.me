<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack delete endpoint: only the owner may remove
 * a stack from their collection.
 */
class DeleteCardStackRequest extends FormRequest
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
