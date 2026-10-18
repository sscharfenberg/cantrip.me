<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use App\Traits\ReturnsToPage;
use Illuminate\Foundation\Http\FormRequest;

class EditCardStackRequest extends FormRequest
{
    use ReturnsToPage;

    /**
     * The authenticated user must own the card stack.
     */
    public function authorize(): bool
    {
        $cardStack = $this->route('cardStack');

        return $cardStack instanceof CardStack && $cardStack->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
