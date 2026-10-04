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
 *
 * `command_zone_printings` (oracle id → printing id) carries the printing
 * the paste named for a command-zone card; each key must be one of the
 * command-zone ids sent alongside, each value a printing of that card.
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
            'command_zone_printings' => ['nullable', 'array', 'max:3'],
            'command_zone_printings.*' => ['string'],
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
                $commandPrintings = $this->input('command_zone_printings') ?? [];
                $oracleByPrinting = DefaultCard::query()
                    ->whereIn('id', array_values(array_unique([...array_column($rows, 'default_card_id'), ...array_values($commandPrintings)])))
                    ->pluck('oracle_id', 'id')
                    ->all();
                foreach ($rows as $index => $row) {
                    if (($oracleByPrinting[$row['default_card_id']] ?? null) !== $row['oracle_card_id']) {
                        $validator->errors()->add("rows.{$index}.default_card_id", __('validation.custom.deck_list.printing_mismatch'));
                    }
                }
                // Keyed by oracle id: each must be a card the command zone
                // actually holds, and the printing one of that card's.
                $commandZone = array_filter([$this->input('commander_id'), $this->input('companion_id'), $this->input('signature_spell_id')]);
                foreach ($commandPrintings as $oracleId => $printingId) {
                    if (! in_array($oracleId, $commandZone, true) || ($oracleByPrinting[$printingId] ?? null) !== $oracleId) {
                        $validator->errors()->add("command_zone_printings.{$oracleId}", __('validation.custom.deck_list.printing_mismatch'));
                    }
                }
            },
        ];
    }
}
