<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the GET "add cards" page.
 *
 * Sibling to {@see AddCardStackRequest} (which carries the POST rules).
 * Kept separate so the GET doesn't run the POST's required-field rules
 * — those would always fail on a bodyless GET and trigger a redirect-back.
 */
class ShowAddCardStackRequest extends FormRequest
{
    /**
     * When a container is provided, the authenticated user must own it.
     */
    public function authorize(): bool
    {
        $container = $this->route('container');

        if (! $container) {
            return true;
        }

        return $container instanceof Container && $container->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
