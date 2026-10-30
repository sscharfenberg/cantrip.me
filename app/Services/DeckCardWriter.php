<?php

namespace App\Services;

use App\Enums\CardLanguage;
use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
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
 * its zone with its custom category, created on first use — each claiming
 * the card stacks it names, where they are still free to claim.
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
     * `companion`. `card_stack_ids` are the stacks a row asks to claim, in
     * the order it names them; which of them it gets is {@see claim}'s
     * call, for command-zone and companion rows as for every other.
     *
     * `finish` (a label), `language` and `proxy` are the plan for the card's
     * copies ({@see DeckCardCopiesService}); each is optional, and a row
     * without one gets the model's default — English, not a proxy, the
     * printing's first finish. A finish the printing lacks falls back the
     * same way.
     *
     * @param  list<array{oracle_card_id: string, default_card_id: string, role: string, is_partner: bool, zone: string, quantity: int, category: string|null, card_stack_ids: list<string>, finish?: string|null, language?: string|null, proxy?: bool|null}>  $rows
     * @return array{imported: int, commanders: int, companion: int}
     */
    public static function write(Deck $deck, array $rows): array
    {
        return DB::transaction(function () use ($deck, $rows): array {
            $categoryMap = self::ensureCategories($deck, $rows);
            $claimable = self::claimableStacks($deck, $rows);

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
                    $deckCard = DeckCard::create([
                        'deck_id' => $deck->id,
                        'oracle_card_id' => $row['oracle_card_id'],
                        'default_card_id' => $row['default_card_id'],
                        'zone' => DeckZone::Command->value,
                        'role' => $role,
                        'quantity' => 1,
                        ...self::plan($row),
                    ]);
                    self::claim($deckCard, $row['card_stack_ids'], $claimable);
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
                    $deckCard = DeckCard::create([
                        'deck_id' => $deck->id,
                        'oracle_card_id' => $row['oracle_card_id'],
                        'default_card_id' => $row['default_card_id'],
                        'zone' => DeckZone::Companion->value,
                        'role' => DeckCardRole::Companion->value,
                        'quantity' => 1,
                        ...self::plan($row),
                    ]);
                    self::claim($deckCard, $row['card_stack_ids'], $claimable);
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
                    ...self::plan($row),
                ]);
                $imported += $row['quantity'];
                self::claim($deckCard, $row['card_stack_ids'], $claimable);
            }

            return [
                'imported' => $imported,
                'commanders' => $commanders,
                'companion' => $companion,
            ];
        });
    }

    /**
     * The plan values a row names, as model attributes — absent ones are left
     * to the model's defaults.
     *
     * @param  array{finish?: string|null, language?: string|null, proxy?: bool|null}  $row
     * @return array{finish?: Finish, language?: CardLanguage, proxy?: bool}
     */
    private static function plan(array $row): array
    {
        $plan = [];
        $finish = Finish::fromLabel((string) ($row['finish'] ?? ''));
        if ($finish !== null) {
            $plan['finish'] = $finish;
        }
        $language = CardLanguage::tryFrom((string) ($row['language'] ?? ''));
        if ($language !== null) {
            $plan['language'] = $language;
        }
        if (isset($row['proxy'])) {
            $plan['proxy'] = (bool) $row['proxy'];
        }

        return $plan;
    }

    /**
     * The stacks the rows ask for that a new deck card may claim, keyed by id:
     * the deck owner's, and claimed by no deck card on any deck. A cantrip
     * export names the stacks its deck claims, so without the second rule
     * importing it beside that deck would have both claim the same cards.
     *
     * @param  list<array{card_stack_ids: list<string>}>  $rows
     * @return array<string, CardStack>
     */
    private static function claimableStacks(Deck $deck, array $rows): array
    {
        $ids = array_values(array_unique(array_merge(...array_column($rows, 'card_stack_ids'))));
        if ($ids === []) {
            return [];
        }

        return CardStack::query()
            ->where('user_id', $deck->user_id)
            ->whereIn('id', $ids)
            ->whereDoesntHave('deckCards')
            ->get(['id', 'default_card_id', 'amount'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Claim the stacks a row asks for, in its order: each one that is still
     * claimable (an earlier row of this write may have taken it), of the
     * card's printing and small enough to fit the copies not yet claimed. A
     * stack that would overflow is skipped, never split, and a smaller one
     * after it may still fit; every id passed over is dropped silently, the
     * card itself is written either way.
     *
     * Claims that cover every copy hand the card their finish, language and
     * proxy flag ({@see DeckCardCopiesService::adoptClaimedValues}), as on the
     * other claim flows.
     *
     * @param  list<string>  $stackIds
     * @param  array<string, CardStack>  $claimable  Loses every stack claimed here.
     */
    private static function claim(DeckCard $deckCard, array $stackIds, array &$claimable): void
    {
        $claimed = [];
        $covered = 0;
        foreach ($stackIds as $id) {
            $stack = $claimable[$id] ?? null;
            if ($stack === null
                || $stack->default_card_id !== $deckCard->default_card_id
                || $covered + $stack->amount > $deckCard->quantity) {
                continue;
            }
            $claimed[] = $id;
            $covered += $stack->amount;
            unset($claimable[$id]);
        }
        if ($claimed === []) {
            return;
        }

        $deckCard->cardStacks()->attach($claimed);
        DeckCardCopiesService::adoptClaimedValues($deckCard);
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
