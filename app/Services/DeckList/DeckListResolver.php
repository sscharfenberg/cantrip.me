<?php

namespace App\Services\DeckList;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Formats\FormatProfile;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\User;
use App\Services\CardNameNormalizer;
use App\Services\CommandZoneService;
use App\Services\DeckCardSearchService;
use App\Services\DeckCollectionStatusService;
use App\Services\OracleNameSearch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns {@see DeckListParser} output into cards — read-only, for the review
 * page of the deck list import.
 *
 * Per line, the first rule that applies:
 *
 *  1. **set + number** → that printing, provided it is a printing of the
 *     named card. A number that is wrong, or names another card, is set
 *     aside with a `printing_not_found` notice and the line falls through.
 *  2. **set only** → the card by name, printing chosen within that set.
 *  3. **name only** → the card by name, printing chosen across all of them.
 *
 * Names match **exactly** on the normalized name ({@see CardNameNormalizer}),
 * tried against the English name, the front face's name, then the
 * translation tables — never the LIKE search, which would happily turn
 * "Bolt" into Lightning Bolt. A name that matches nothing, or several cards
 * none of which is legal in the format, is unresolved; the review page then
 * runs {@see search} with the pasted name already filled in.
 *
 * The printing, when the paste does not pin one, follows Quick Add: the one
 * the user has most free copies of, ties to the newest, the newest when
 * nothing is free ({@see DeckCardSearchService::preferredPrintingId}). Each
 * card also carries the planned-deck availability the deck page will show
 * once the deck exists ({@see DeckCollectionStatusService::availabilityFor}).
 *
 * The command zone is pre-filled from the lines the paste put there, when
 * they pass the picker's own eligibility rules; anything else in the command
 * zone falls back to the main deck with a `not_commander` notice.
 */
final class DeckListResolver
{
    /** Results of one replacement search on the review page. */
    public const SEARCH_LIMIT = 20;

    /**
     * @param  array{sections: list<array>, lines: list<array>, dropped: int}  $parsed  {@see DeckListParser::parse()}
     * @return array{
     *     sections: list<array>,
     *     lines: list<array>,
     *     dropped: int,
     *     command_zone: array{commander: array|null, partner: array|null, signature_spell: array|null, printings: array<string, string>},
     *     rules: array<string, mixed>,
     *     collection: bool,
     * }
     */
    public static function resolve(User $user, CardFormat $format, array $parsed): array
    {
        $profile = $format->rules();
        $lines = $parsed['lines'];

        $pinned = self::pinnedPrintings($lines);
        $candidates = self::oracleCandidates(array_values(array_unique(array_filter(array_column($lines, 'name')))));
        $legalIds = self::legalOracleIds(array_merge([], ...array_values($candidates)), $format);

        // First pass: decide each line's oracle card (and pinned printing).
        $resolved = [];
        foreach ($lines as $line) {
            $entry = $line + [
                'status' => 'unresolved',
                'reason' => null,
                'notices' => [],
                'oracle_card_id' => null,
                'printing_id' => null,
                'card' => null,
                'availability' => null,
            ];

            if ($line['name'] === null) {
                $entry['reason'] = 'unparseable';
                $resolved[] = $entry;

                continue;
            }

            $normalized = CardNameNormalizer::normalize($line['name']);

            if ($line['set'] !== null && $line['number'] !== null) {
                $printing = $pinned["{$line['set']}|{$line['number']}"] ?? null;
                if ($printing !== null && in_array($normalized, $printing['names'], true)) {
                    $entry['oracle_card_id'] = $printing['oracle_id'];
                    $entry['printing_id'] = $printing['id'];
                } else {
                    $entry['notices'][] = 'printing_not_found';
                }
            }

            if ($entry['oracle_card_id'] === null) {
                $ids = $candidates[$normalized] ?? [];
                if (count($ids) > 1) {
                    $legal = array_values(array_filter($ids, fn (string $id): bool => isset($legalIds[$id])));
                    $ids = $legal !== [] ? $legal : $ids;
                }
                if (count($ids) === 1) {
                    $entry['oracle_card_id'] = $ids[0];
                } else {
                    $entry['reason'] = $ids === [] ? 'not_found' : 'ambiguous';
                }
            }

            $resolved[] = $entry;
        }

        // One batch for every resolved line's card.
        $requests = [];
        foreach ($resolved as $i => $entry) {
            if ($entry['oracle_card_id'] !== null) {
                $requests["line:{$i}"] = [
                    'oracle_card_id' => $entry['oracle_card_id'],
                    'printing_id' => $entry['printing_id'],
                    'set' => $entry['set'],
                    'quantity' => $entry['quantity'],
                ];
            }
        }
        ['cards' => $cards, 'oracles' => $oracles] = self::cardsWithOracles($user, $format, $requests);

        foreach ($resolved as $i => $entry) {
            if (isset($cards["line:{$i}"])) {
                $result = $cards["line:{$i}"];
                $resolved[$i]['status'] = 'resolved';
                $resolved[$i]['reason'] = null;
                $resolved[$i]['card'] = $result['card'];
                $resolved[$i]['availability'] = $result['availability'];
                $resolved[$i]['notices'] = array_values(array_unique(array_merge($entry['notices'], $result['notices'])));
            } elseif ($entry['oracle_card_id'] !== null) {
                // An oracle card without a single printing — broken data, not
                // something the user can fix by picking it again.
                $resolved[$i]['reason'] = 'not_found';
            }
        }

        [$commandZone, $resolved] = self::commandZone($resolved, $oracles, $profile, $format);
        $resolved = self::placeCompanions($resolved, $profile);

        return [
            'sections' => $parsed['sections'],
            'lines' => array_map(self::present(...), $resolved),
            'dropped' => $parsed['dropped'],
            'command_zone' => $commandZone,
            'rules' => $profile->toArray(),
            'collection' => (bool) $user->collection_integration_enabled,
        ];
    }

    /**
     * Name search for the review page's "replace this line" field, which
     * opens pre-filled with the pasted name.
     *
     * Every word must match, as in the other card searches. When that finds
     * nothing it retries one word at a time, longest first — a typo in one
     * word ("Lightnig Bolt") would otherwise sink the whole pre-filled query,
     * while "bolt" alone still finds the card. Not limited to the format's
     * pool: a user replacing a card knows what they want, and an illegal pick
     * is flagged, not hidden.
     *
     * @return list<array{card: array, availability: array|null}>
     */
    public static function search(User $user, CardFormat $format, string $query, int $quantity): array
    {
        $segments = self::segments($query);
        if ($segments === []) {
            return [];
        }

        $ids = self::nameSearch($segments);
        if ($ids === [] && count($segments) > 1) {
            $words = array_filter(array_unique($segments), fn (string $word): bool => strlen($word) >= 3);
            usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($words as $word) {
                $ids = self::nameSearch([$word]);
                if ($ids !== []) {
                    break;
                }
            }
        }
        $requests = [];
        foreach ($ids as $k => $id) {
            $requests[(string) $k] = ['oracle_card_id' => $id, 'printing_id' => null, 'set' => null, 'quantity' => $quantity];
        }
        $cards = self::cardsWithOracles($user, $format, $requests)['cards'];

        $results = [];
        foreach (array_keys($requests) as $key) {
            if (isset($cards[$key])) {
                $results[] = ['card' => $cards[$key]['card'], 'availability' => $cards[$key]['availability']];
            }
        }

        return $results;
    }

    /**
     * The printings named by set + collector number, with the normalized
     * names they answer to (English, front face) for the name check.
     *
     * @param  list<array{set: string|null, number: string|null}>  $lines
     * @return array<string, array{id: string, oracle_id: string, names: list<string>}> Keyed `set|number`.
     */
    private static function pinnedPrintings(array $lines): array
    {
        $pairs = [];
        foreach ($lines as $line) {
            if ($line['set'] !== null && $line['number'] !== null) {
                $pairs[$line['set']][$line['number']] = true;
            }
        }
        if ($pairs === []) {
            return [];
        }

        $rows = DB::table('default_cards')
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->join('oracle_cards', 'oracle_cards.id', '=', 'default_cards.oracle_id')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $set => $numbers) {
                    $query->orWhere(fn ($q) => $q->where('sets.code', $set)->whereIn('default_cards.collector_number', array_map('strval', array_keys($numbers))));
                }
            })
            ->get(['default_cards.id', 'default_cards.oracle_id', 'default_cards.collector_number', 'sets.code', 'oracle_cards.searchable_name']);

        $faceNames = OracleCardFace::query()
            ->whereIn('oracle_card_id', $rows->pluck('oracle_id')->unique()->all())
            ->where('face_index', 0)
            ->pluck('name', 'oracle_card_id')
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $names = [(string) $row->searchable_name];
            if (isset($faceNames[$row->oracle_id])) {
                $names[] = CardNameNormalizer::normalize($faceNames[$row->oracle_id]);
            }
            $map[strtolower($row->code).'|'.$row->collector_number] = [
                'id' => $row->id,
                'oracle_id' => $row->oracle_id,
                'names' => $names,
            ];
        }

        return $map;
    }

    /**
     * Oracle cards each name could mean, by exact normalized match — English
     * name first, then front-face name, then translations. The first source
     * that knows a name answers for it.
     *
     * @param  list<string>  $names  Raw names from the paste.
     * @return array<string, list<string>> Normalized name → oracle ids.
     */
    private static function oracleCandidates(array $names): array
    {
        $byNormalized = [];
        foreach ($names as $name) {
            $normalized = CardNameNormalizer::normalize($name);
            if ($normalized !== '') {
                $byNormalized[$normalized][] = $name;
            }
        }
        if ($byNormalized === []) {
            return [];
        }

        $found = [];
        $add = function (string $normalized, string $oracleId) use (&$found): void {
            $found[$normalized][$oracleId] = true;
        };

        foreach (array_chunk(array_keys($byNormalized), 500) as $chunk) {
            OracleCard::query()->whereIn('searchable_name', $chunk)
                ->get(['id', 'searchable_name'])
                ->each(fn (OracleCard $card) => $add((string) $card->searchable_name, $card->id));
        }

        $missing = array_diff_key($byNormalized, $found);
        if ($missing !== []) {
            $rawNames = array_merge(...array_values($missing));
            OracleCardFace::query()
                ->whereIn('name', $rawNames)
                ->where('face_index', 0)
                ->get(['oracle_card_id', 'name'])
                ->each(function (OracleCardFace $face) use ($add, $missing): void {
                    $normalized = CardNameNormalizer::normalize((string) $face->name);
                    if (isset($missing[$normalized])) {
                        $add($normalized, $face->oracle_card_id);
                    }
                });
        }

        $missing = array_diff_key($byNormalized, $found);
        if ($missing !== []) {
            foreach ([OracleNameSearch::ORACLE_TRANSLATION_TABLE, OracleNameSearch::FACE_TRANSLATION_TABLE] as $table) {
                DB::table($table)
                    ->whereIn('searchable_name', array_keys($missing))
                    ->whereIn('lang', OracleNameSearch::SEARCHABLE_LANGS)
                    ->when($table === OracleNameSearch::FACE_TRANSLATION_TABLE, fn ($q) => $q->where('face_index', 0))
                    ->get(['oracle_card_id', 'searchable_name'])
                    ->each(fn (object $row) => $add((string) $row->searchable_name, (string) $row->oracle_card_id));
            }
        }

        return array_map(fn (array $ids): array => array_keys($ids), $found);
    }

    /**
     * Ids among `$oracleIds` that are legal (or restricted) in the format.
     *
     * @param  list<string>  $oracleIds
     * @return array<string, true>
     */
    private static function legalOracleIds(array $oracleIds, CardFormat $format): array
    {
        if ($oracleIds === []) {
            return [];
        }

        return array_fill_keys(
            OracleCard::query()->whereIn('id', array_unique($oracleIds))->legalIn($format)->pluck('id')->all(),
            true,
        );
    }

    /**
     * Oracle ids whose name matches every segment, best match first.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function nameSearch(array $segments): array
    {
        $query = OracleCard::query()->select('oracle_cards.id');
        OracleNameSearch::applyMultiTableNameSegments($query, $segments);
        OracleNameSearch::applyNameRanking($query, $segments);

        return $query->limit(self::SEARCH_LIMIT)->pluck('id')->all();
    }

    /** @return list<string> */
    private static function segments(string $text): array
    {
        $normalized = CardNameNormalizer::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * The card payload, chosen printing and availability for each request,
     * in one batch — plus the loaded oracle cards, for the command-zone
     * eligibility checks that follow.
     *
     * @param  array<string, array{oracle_card_id: string, printing_id: string|null, set: string|null, quantity: int}>  $requests
     * @return array{cards: array<string, array{card: array, availability: array|null, notices: list<string>}>, oracles: Collection<string, OracleCard>}
     */
    private static function cardsWithOracles(User $user, CardFormat $format, array $requests): array
    {
        $oracleIds = array_values(array_unique(array_column($requests, 'oracle_card_id')));
        if ($oracleIds === []) {
            return ['cards' => [], 'oracles' => collect()];
        }

        $profile = $format->rules();
        $oracles = OracleCard::query()
            ->whereIn('id', $oracleIds)
            ->with([
                'faces' => fn ($q) => $q->select('oracle_card_id', 'face_index', 'name', 'mana_cost', 'type_line', 'oracle_text', 'power', 'toughness', 'loyalty')
                    ->orderBy('face_index'),
                'legalities' => fn ($q) => $q->where('format', $format->value),
            ])
            ->get(['id', 'name', 'color_identity', 'searchable_name'])
            ->keyBy('id');

        // Every printing of every card, newest first — the order the free
        // map uses, so "first" means "newest" on both sides.
        $printings = [];
        DB::table('default_cards')
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->whereIn('default_cards.oracle_id', $oracleIds)
            ->orderByDesc('sets.released_at')
            ->orderByDesc('default_cards.id')
            ->get(['default_cards.id', 'default_cards.oracle_id', 'default_cards.collector_number', 'default_cards.card_image_0', 'sets.code', 'sets.name', 'sets.path'])
            ->each(function (object $row) use (&$printings): void {
                $printings[$row->oracle_id][$row->id] = [
                    'set' => strtolower((string) $row->code),
                    'number' => (string) $row->collector_number,
                    'image' => $row->card_image_0,
                    'set_name' => $row->name,
                    'set_path' => $row->path,
                ];
            });

        ['free' => $free, 'held' => $held] = DeckCollectionStatusService::freeCopiesForUser($user, $oracleIds);
        $collection = (bool) $user->collection_integration_enabled;

        $cards = [];
        foreach ($requests as $key => $request) {
            $oracle = $oracles->get($request['oracle_card_id']);
            $all = $printings[$request['oracle_card_id']] ?? [];
            if ($oracle === null || $all === []) {
                continue;
            }

            $notices = [];
            $printingId = $request['printing_id'];
            if ($printingId === null || ! isset($all[$printingId])) {
                $pool = $all;
                if ($request['set'] !== null) {
                    $inSet = array_filter($all, fn (array $p): bool => $p['set'] === $request['set']);
                    if ($inSet === []) {
                        $notices[] = 'printing_not_found';
                    } else {
                        $pool = $inSet;
                    }
                }
                $freeInPool = array_intersect_key($free[$oracle->id] ?? [], $pool);
                $printingId = DeckCardSearchService::preferredPrintingId($freeInPool, array_key_first($pool));
            }

            $cards[$key] = [
                'card' => self::cardPayload($oracle, $profile, $printingId, $all[$printingId]),
                'availability' => $collection
                    ? DeckCollectionStatusService::availabilityFor($free[$oracle->id] ?? [], $held[$oracle->id] ?? 0, $printingId, $request['quantity'])
                    : null,
                'notices' => $notices,
            ];
        }

        return ['cards' => $cards, 'oracles' => $oracles];
    }

    /**
     * The facts the review page needs about one card. Warnings that depend
     * on the user's edits — copies across lines, colour identity against the
     * chosen commander — are derived client-side from `is_legal`,
     * `copy_limit` and `color_identity`.
     *
     * @param  array{set: string, number: string, image: string|null, set_name: string|null, set_path: string|null}  $printing
     * @return array{oracle_card_id: string, name: string, color_identity: string|null, type_line: string|null, is_legal: bool, copy_limit: int|null, default_card_id: string, set_code: string, set_name: string|null, set_path: string|null, collector_number: string, image: string|null}
     */
    private static function cardPayload(OracleCard $oracle, FormatProfile $profile, string $printingId, array $printing): array
    {
        $legality = $oracle->legalities->first()?->legality;
        $legalityValue = $legality instanceof CardLegality ? $legality->value : $legality;
        $isLegal = in_array($legalityValue, [CardLegality::Legal->value, CardLegality::Restricted->value], true)
            && $profile->isInPool($oracle);

        $copyLimit = match (true) {
            in_array($oracle->name, FormatProfile::BASIC_LANDS, true), $oracle->hasUnlimitedCopiesRule() => null,
            $legalityValue === CardLegality::Restricted->value => 1,
            default => $profile->maxCopies(),
        };

        return [
            'oracle_card_id' => $oracle->id,
            'name' => $oracle->name,
            'color_identity' => $oracle->color_identity,
            'type_line' => $oracle->faces->first()?->type_line,
            'is_legal' => $isLegal,
            'copy_limit' => $copyLimit,
            'default_card_id' => $printingId,
            'set_code' => $printing['set'],
            'set_name' => $printing['set_name'],
            'set_path' => $printing['set_path'],
            'collector_number' => $printing['number'],
            // Front face, for the review row's thumbnail.
            'image' => $printing['image'],
        ];
    }

    /**
     * Pre-fill the command zone from the lines the paste put there.
     *
     * Only for formats with a command zone, and only with cards the picker
     * itself would offer: a legal commander and a partner it pairs with, or
     * an Oathbreaker and a signature spell inside its colours. The cards used
     * leave the line list — they live in the command zone block now — and
     * their printing is kept in `printings`, so the deck gets the printing
     * the paste named (or the collection offers) instead of the newest.
     * Every other command-zone line moves to the main deck with a
     * `not_commander` notice, so nothing the user pasted silently vanishes.
     *
     * Two passes, never paste order: sites sort the command zone by name, so
     * a Background is as likely to come before its commander as after it.
     * The head of the zone is chosen first, preferring a commander whose
     * pairing card is also in the paste; the second slot is matched to it.
     *
     * @param  list<array>  $lines
     * @param  Collection<string, OracleCard>  $oracles
     * @return array{0: array{commander: array|null, partner: array|null, signature_spell: array|null, printings: array<string, string>}, 1: list<array>}
     */
    private static function commandZone(array $lines, Collection $oracles, FormatProfile $profile, CardFormat $format): array
    {
        $zone = ['commander' => null, 'partner' => null, 'signature_spell' => null, 'printings' => []];

        /** @var array<int, OracleCard> $eligible Legal, resolved command-zone lines, by line index. */
        $eligible = [];
        if ($profile->requiresCommander()) {
            foreach ($lines as $i => $line) {
                if ($line['zone'] === DeckListParser::ZONE_COMMAND && $line['card'] !== null && $line['card']['is_legal']) {
                    $card = $oracles->get($line['card']['oracle_card_id']);
                    if ($card !== null) {
                        $eligible[$i] = $card;
                    }
                }
            }
        }

        $head = null;
        $second = null;
        if ($profile->hasSignatureSpell()) {
            $head = array_key_first(array_filter($eligible, fn (OracleCard $card): bool => CommandZoneService::isOathbreakerCandidate($card)));
            if ($head !== null) {
                $second = array_key_first(array_filter(
                    $eligible,
                    fn (OracleCard $card, int $i): bool => $i !== $head && CommandZoneService::isSignatureSpellCandidate($card, $eligible[$head]->color_identity),
                    ARRAY_FILTER_USE_BOTH,
                ));
            }
        } else {
            $candidates = array_filter($eligible, fn (OracleCard $card): bool => CommandZoneService::isCommanderCandidate($card, $format));
            foreach ($candidates as $i => $commander) {
                $partner = array_key_first(array_filter(
                    $eligible,
                    fn (OracleCard $card, int $j): bool => $j !== $i && CommandZoneService::pairsWithCommander($commander, $card, $format),
                    ARRAY_FILTER_USE_BOTH,
                ));
                if ($partner !== null) {
                    [$head, $second] = [$i, $partner];
                    break;
                }
            }
            $head ??= array_key_first($candidates);
        }

        $used = [];
        foreach ([['commander', $head], [$profile->hasSignatureSpell() ? 'signature_spell' : 'partner', $second]] as [$slot, $i]) {
            if ($i === null) {
                continue;
            }
            $zone[$slot] = CommandZoneService::mapCommanderCard($eligible[$i]);
            $zone['printings'][$eligible[$i]->id] = $lines[$i]['card']['default_card_id'];
            $used[$i] = true;
        }

        $rest = [];
        foreach ($lines as $i => $line) {
            if (isset($used[$i])) {
                continue;
            }
            if ($line['zone'] === DeckListParser::ZONE_COMMAND) {
                $line['zone'] = DeckListParser::ZONE_MAIN;
                if ($profile->requiresCommander() && $line['card'] !== null) {
                    $line['notices'][] = 'not_commander';
                }
            }
            $rest[] = $line;
        }

        return [$zone, $rest];
    }

    /**
     * The first companion line keeps the companion slot when the format has
     * one; any other lands in the sideboard, where a constructed deck keeps
     * its companion anyway.
     *
     * @param  list<array>  $lines
     * @return list<array>
     */
    private static function placeCompanions(array $lines, FormatProfile $profile): array
    {
        $taken = ! $profile->allowsCompanion();
        foreach ($lines as $i => $line) {
            if ($line['zone'] !== DeckListParser::ZONE_COMPANION) {
                continue;
            }
            if ($taken || $line['card'] === null) {
                $lines[$i]['zone'] = DeckListParser::ZONE_SIDE;

                continue;
            }
            $taken = true;
        }

        return $lines;
    }

    /**
     * The line as the review page receives it — working fields dropped.
     *
     * @return array<string, mixed>
     */
    private static function present(array $line): array
    {
        return [
            'line' => $line['line'],
            'raw' => $line['raw'],
            'section' => $line['section'],
            'zone' => $line['zone'],
            'zone_guessed' => $line['zone_guessed'],
            'quantity' => $line['quantity'],
            'name' => $line['name'],
            'category' => $line['category'],
            'status' => $line['status'],
            'reason' => $line['reason'],
            'notices' => array_values(array_unique($line['notices'])),
            'card' => $line['card'],
            'availability' => $line['availability'],
        ];
    }
}
