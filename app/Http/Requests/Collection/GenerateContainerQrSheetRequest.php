<?php

namespace App\Http\Requests\Collection;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateContainerQrSheetRequest extends FormRequest
{
    /**
     * Authenticated user must own every container id passed in `?ids=`.
     *
     * The id list arrives as a single comma-separated string in the
     * query — keeps URLs shorter than `ids[]=…&ids[]=…` and survives
     * `window.location.href` triggering for the download.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize the comma-separated `ids` query parameter into an array
     * of strings before validation so the `ids.*` rules can fire.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->query('ids', '');

        if (is_string($raw)) {
            $ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } elseif (is_array($raw)) {
            $ids = array_values(array_filter(array_map('trim', $raw)));
        } else {
            $ids = [];
        }

        $this->merge(['ids' => $ids]);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'uuid',
                Rule::exists(Container::class, 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
