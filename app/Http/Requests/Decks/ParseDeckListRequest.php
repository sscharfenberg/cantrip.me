<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a pasted deck list before it is parsed and resolved.
 *
 * Read-only endpoint, but each line costs lookups, so the paste is bounded:
 * {@see self::MAX_CHARS} characters and {@see self::MAX_LINES} non-blank
 * lines — a 250-card list with headers fits with room to spare.
 */
class ParseDeckListRequest extends FormRequest
{
    public const MAX_CHARS = 20000;

    public const MAX_LINES = 500;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::enum(CardFormat::class)],
            'text' => ['required', 'string', 'max:'.self::MAX_CHARS],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $text = $this->input('text');
                if (! is_string($text)) {
                    return;
                }
                $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
                $nonBlank = count(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
                if ($nonBlank > self::MAX_LINES) {
                    $validator->errors()->add('text', __('validation.custom.deck_list.too_many_lines', ['max' => self::MAX_LINES]));
                }
            },
        ];
    }
}
