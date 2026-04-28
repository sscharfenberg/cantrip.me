<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the container-prune endpoint (delete all card stacks inside
 * a container without deleting the container itself): only the owner may
 * trigger it.
 */
class PruneContainerRequest extends FormRequest
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
