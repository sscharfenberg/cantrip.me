<?php

namespace App\Services\DeckList;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use App\Services\DeckCardService;
use App\Services\DeckCardWriter;
use App\Services\DeckService;
use Illuminate\Support\Facades\DB;

/**
 * Creates the deck the deck list import's review page confirmed.
 *
 * Everything the user decided on the review page arrives resolved: oracle
 * card and printing per row, the command zone as the create form sends it.
 * One transaction creates the deck exactly like the create form
 * ({@see DeckService::createDeck}) and writes the rows through the writer the
 * CSV import uses ({@see DeckCardWriter}), so a failure leaves no deck behind.
 */
final class DeckListImportService
{
    /**
     * @param  array{format: string, deck_name: string, commander_id?: string|null, companion_id?: string|null, signature_spell_id?: string|null, command_zone_printings?: array<string, string>|null, rows: list<array{oracle_card_id: string, default_card_id: string, quantity: int, zone: string, category?: string|null}>}  $data
     */
    public static function import(User $user, array $data): Deck
    {
        return DB::transaction(function () use ($user, $data): Deck {
            $deck = DeckService::createDeck($user, [
                'format' => $data['format'],
                'deck_name' => $data['deck_name'],
                'commander_id' => $data['commander_id'] ?? null,
                'companion_id' => $data['companion_id'] ?? null,
                'signature_spell_id' => $data['signature_spell_id'] ?? null,
            ]);

            // The create form's command zone takes the newest printing; the
            // paste may have named another, or the collection offered one.
            foreach ($data['command_zone_printings'] ?? [] as $oracleId => $printingId) {
                DeckCard::query()
                    ->where('deck_id', $deck->id)
                    ->where('zone', DeckZone::Command->value)
                    ->where('oracle_card_id', $oracleId)
                    ->update(['default_card_id' => $printingId]);
            }

            DeckCardWriter::write($deck, self::mergeRows($data['rows']));

            DeckCardService::recalculateColors($deck);
            $deck->syncHeroImage();

            return $deck;
        });
    }

    /**
     * One writer row per distinct card, printing, zone and category — two
     * pasted lines of the same card (a list split across headers, a
     * replacement that picked a card already listed) become one deck row.
     *
     * @param  list<array{oracle_card_id: string, default_card_id: string, quantity: int, zone: string, category?: string|null}>  $rows
     * @return list<array{oracle_card_id: string, default_card_id: string, role: string, is_partner: bool, zone: string, quantity: int, category: string|null, card_stack_ids: list<string>}>
     */
    private static function mergeRows(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $category = isset($row['category']) && trim($row['category']) !== '' ? trim($row['category']) : null;
            $isCompanion = $row['zone'] === DeckListParser::ZONE_COMPANION;
            if ($isCompanion) {
                $category = null;
            }
            $key = implode("\0", [$row['oracle_card_id'], $row['default_card_id'], $row['zone'], $category ?? '']);

            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += (int) $row['quantity'];

                continue;
            }

            $merged[$key] = [
                'oracle_card_id' => $row['oracle_card_id'],
                'default_card_id' => $row['default_card_id'],
                'role' => $isCompanion ? 'companion' : 'card',
                'is_partner' => false,
                'zone' => $row['zone'],
                'quantity' => (int) $row['quantity'],
                'category' => $category,
                'card_stack_ids' => [],
            ];
        }

        return array_values($merged);
    }
}
