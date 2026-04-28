<?php

namespace App\Http\Requests\Collection;

use App\Enums\ContainerVisibility;
use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the public container show page.
 *
 * Public containers are visible to anyone with the link; private ones are
 * visible only to the owner. The route is mounted outside the auth group,
 * so unauthenticated visitors must be tolerated when the container is
 * public — `$this->user()` may be null.
 *
 * Failure throws a 404 (not the FormRequest-default 403) to hide the
 * existence of private containers from non-owners — the same behaviour
 * the previous inline check enforced.
 */
class ShowContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $container = $this->route('container');
        if (! $container instanceof Container) {
            return false;
        }

        return $container->visibility === ContainerVisibility::Public
            || $container->user_id === $this->user()?->id;
    }

    /**
     * Throw 404 instead of the default 403 — we don't want non-owners
     * to be able to enumerate the existence of private containers.
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
