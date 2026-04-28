<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the mass-move endpoint (move every card stack from one
 * container to another or to unsorted): only the source container's
 * owner may trigger it. The target container is bound by the form
 * field `container_id` and verified separately via
 * {@see CardStackService::resolveOwnedContainer}.
 */
class MassMoveCardStacksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $container = $this->route('container');

        return $container instanceof Container && $container->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
