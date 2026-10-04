<?php

namespace App\Services;

use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes resolved card rows onto a deck — the write side both deck imports
 * share.
 *
 * The CSV import ({@see DeckCsvImportService}) and the deck list import
 * ({@see DeckList\DeckListImportService}) find their cards very differently
 * — by Scryfall id, by set + collector number, by name — but once every row
 * names an oracle card and a printing, putting them on the deck is the same
 * job: command-zone rows by role, the first companion, everything else into
 * its zone with its custom category, created on first use.
 *
 * Purely additive, like the CSV import always was: nothing on the deck is
 * removed. The caller decides what state the deck is in beforehand.
 */
final class DeckCardWriter
{
    /**
     * Write the rows in one transaction.
     *
     * `role` is `card`, `commander`, `partner`, `signature_spell` or
     * `companion`; `card_stack_ids` must already be narrowed to stacks the
     * deck's owner owns.
     *
     * @param  list<array{oracle_card_id: string, default_card_id: string, role: string, is_partner: bool, zone: string, quantity: int, category: string|null, card_stack_ids: list<string>}>  $rows
     * @return array{imported: int, commanders: int, companion: int}
     */
    public static function write(Deck $deck, array $rows): array
    {
        return DB::transaction(function () use ($deck, $rows): array {
            $categoryMap = self::ensureCategories($deck, $rows);

            $imported = 0;
            $commanders = 0;
            $companion = 0;

            foreach ($rows as $row) {
                if (in_array($row['role'], ['commander', 'partner', 'signature_spell'], true)) {
                    // Three valid command-zone role strings:
                    //  - `commander` (legacy or fresh): primary commander.
                    //    For backward-compat with pre-consolidation CSVs
                    //    that only had `Role=commander` + `Is Partner`,
                    //    we still honour `is_partner=true` to derive
                    //    `partner` (Commander format) or `signature_spell`
                    //    (Oathbreaker).
                    //  - `partner` / `signature_spell` (post-consolidation):
                    //    explicit role string; used directly.
                    if ($row['role'] === 'commander') {
                        $role = $row['is_partner']
                            ? ($deck->format->rules()->hasSignatureSpell()
                                ? DeckCardRole::SignatureSpell->value
                                : DeckCardRole::Partner->value)
                            : DeckCardRole::Commander->value;
                    } else {
                        $role = $row['role'];
                    }
                    DeckCard::create([
                        'deck_id' => $deck->id,
                        'oracle_card_id' => $row['oracle_card_id'],
                        'default_card_id' => $row['default_card_id'],
                        'zone' => DeckZone::Command->value,
                        'role' => $role,
                        'quantity' => 1,
                    ]);
                    $commanders++;

                    continue;
                }

                if ($row['role'] === 'companion') {
                    // First companion row wins; duplicates silently
                    // discarded — `UNIQUE(deck_id, role)` would reject
                    // a second insert anyway.
                    if ($companion > 0) {
                        continue;
                    }
                    DeckCard::create([
                        'deck_id' => $deck->id,
                        'oracle_card_id' => $row['oracle_card_id'],
                        'default_card_id' => $row['default_card_id'],
                        'zone' => DeckZone::Companion->value,
                        'role' => DeckCardRole::Companion->value,
                        'quantity' => 1,
                    ]);
                    $companion++;

                    continue;
                }

                $deckCard = DeckCard::create([
                    'deck_id' => $deck->id,
                    'oracle_card_id' => $row['oracle_card_id'],
                    'default_card_id' => $row['default_card_id'],
                    'category_id' => $row['category'] !== null
                        ? ($categoryMap[$row['category']] ?? null)
                        : null,
                    'zone' => $row['zone'],
                    'quantity' => $row['quantity'],
                ]);
                $imported += $row['quantity'];

                if ($row['card_stack_ids'] !== []) {
                    $deckCard->cardStacks()->attach($row['card_stack_ids']);
                }
            }

            return [
                'imported' => $imported,
                'commanders' => $commanders,
                'companion' => $companion,
            ];
        });
    }

    /**
     * Ensure every distinct category name referenced in `card`-role
     * rows exists on the deck and return a name → id map. Rows with
     * a null category contribute nothing.
     *
     * @param  list<array{role: string, category: string|null}>  $rows
     * @return array<string, string>
     */
    private static function ensureCategories(Deck $deck, array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if ($row['role'] !== 'card') {
                continue;
            }
            $name = $row['category'];
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }

        $existing = DeckCategory::query()
            ->where('deck_id', $deck->id)
            ->whereIn('name', array_keys($names))
            ->pluck('id', 'name')
            ->all();

        $map = $existing;
        foreach (array_keys($names) as $name) {
            if (! isset($map[$name])) {
                $created = DeckCategory::create([
                    'deck_id' => $deck->id,
                    'name' => Str::limit($name, DeckCategory::NAME_MAX, ''),
                ]);
                $map[$name] = $created->id;
            }
        }

        return $map;
    }
}
