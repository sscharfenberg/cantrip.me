<?php

namespace App\Traits;

use App\Models\Container;
use Closure;

/**
 * For the unclaim requests, collection- and deck-side, and for taking
 * claimed copies out of a deck ("−", "Remove"): a stack freed from its deck
 * can be put somewhere else in the same step. `move_to` names one of the
 * user's containers, or {@see self::UNSORTED} for none; without it the
 * stack stays where it is.
 */
trait MovesFreedStacks
{
    /** `move_to` value that takes the stack out of every container. */
    public const UNSORTED = 'unsorted';

    /**
     * The `move_to` rules — merge them into the request's `rules()`.
     *
     * @return array{move_to: array<mixed>}
     */
    protected function moveToRules(): array
    {
        return [
            'move_to' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== self::UNSORTED && $this->ownedContainerId($value) === null) {
                        $fail(__('collection.errors.container_not_found'));
                    }
                },
            ],
        ];
    }

    /**
     * Where the freed stack should go: false to stay put, null for
     * unsorted, or the id — as stored — of one of the user's containers.
     */
    public function moveTarget(): string|false|null
    {
        $moveTo = $this->validated('move_to');

        return match ($moveTo) {
            null, '' => false,
            self::UNSORTED => null,
            default => $this->ownedContainerId($moveTo),
        };
    }

    /** The id of the user's container `$id` names, or null when it names none of theirs. */
    private function ownedContainerId(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        return Container::query()
            ->whereKey($id)
            ->where('user_id', $this->user()?->id)
            ->value('id');
    }
}
