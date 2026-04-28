<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the container-update endpoint: only the owner may modify
 * a container. Sibling of {@see EditContainerRequest} which guards the
 * settings page; this one guards the PATCH that persists the changes.
 */
class UpdateContainerRequest extends FormRequest
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
