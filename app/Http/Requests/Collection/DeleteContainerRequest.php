<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the container-delete endpoint: only the owner may remove
 * their container.
 */
class DeleteContainerRequest extends FormRequest
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
