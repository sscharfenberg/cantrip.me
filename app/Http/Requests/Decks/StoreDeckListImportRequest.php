<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Services\DeckList\DeckListParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the deck the deck list import's review page confirms.
 *
 * The deck fields go through {@see DeckCreationRules}, the create form's own
 * rules. The rows are the review page's resolved lines: every printing must
 * exist and be a printing of the oracle card it is sent with — checked in
 * one query in {@see self::after()} rather than one `exists` per row.
 *
 * `maybe` is not an accepted zone — the import drops the maybeboard — and
 * `command` is not one either: the command zone travels in the create
 * form's `commander_id` / `companion_id` / `signature_spell_id` fields.
 * `companion` is accepted only where the format has the companion mechanic.
 */
class StoreDeckListImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $format = CardFormat::tryFrom((string) $this->input('format', ''));
        $zones = [DeckListParser::ZONE_MAIN, DeckListParser::ZONE_SIDE];
        if ($format?->rules()->allowsCompanion()) {
            $zones[] = DeckListParser::ZONE_COMPANION;
        }

        return DeckCreationRules::for($format) + [
            'rows' => ['present', 'array', 'max:'.ParseDeckListRequest::MAX_LINES],
            'rows.*.oracle_card_id' => ['required', 'string'],
            'rows.*.default_card_id' => ['required', 'string'],
            'rows.*.quantity' => ['required', 'integer', 'between:1,999'],
            'rows.*.zone' => ['required', 'string', Rule::in($zones)],
            'rows.*.category' => ['nullable', 'string', 'max:'.DeckCategory::NAME_MAX],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $rows = $this->input('rows', []);
                $oracleByPrinting = DefaultCard::query()
                    ->whereIn('id', array_values(array_unique(array_column($rows, 'default_card_id'))))
                    ->pluck('oracle_id', 'id')
                    ->all();
                foreach ($rows as $index => $row) {
                    if (($oracleByPrinting[$row['default_card_id']] ?? null) !== $row['oracle_card_id']) {
                        $validator->errors()->add("rows.{$index}.default_card_id", __('validation.custom.deck_list.printing_mismatch'));
                    }
                }
            },
        ];
    }
}
