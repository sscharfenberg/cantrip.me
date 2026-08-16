<?php

namespace App\Services;

use App\Enums\CardFormat;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CommandZoneService
{
    /**
     * Final result count returned to the caller. The frontend renders all
     * results in one batch, so 50 keeps the picker scannable.
     */
    private const RESULT_LIMIT = 50;

    /**
     * Candidate pool size before face-based PHP filters reduce it to
     * RESULT_LIMIT. Sized generously so even after dropping the non-commanders
     * (most matches), 50 valid commanders remain. Empirical worst case:
     * generic terms like "the" hit ~145 oracles via name+legality; we want
     * comfortable headroom above that.
     */
    private const CANDIDATE_LIMIT = 500;

    /**
     * Search for cards that can be a commander (or companion).
     *
     * Two-phase: SQL applies only the cheap, indexed predicates (name,
     * legality, exclude, set), then PHP filters on the eager-loaded `faces`
     * for the commander/partner/companion checks. The previous shape ran a
     * stack of `whereHas('faces', LIKE …)` correlated subqueries against
     * `oracle_card_faces` for every candidate, which dominated the cost on
     * generic terms (~620ms for "the"). Doing those checks once in PHP on
     * the already-loaded faces collection is consistently fast.
     *
     * @param  array{name_segments: string[], normalized_name_segments: string[], set_code: string|null, collector_number: string|null}  $parsed
     * @param  array{rule0: bool, partner: bool, friends_forever: bool, doctors_companion: bool, background: bool, partner_type: string|null, exclude: string|null}  $filters
     */
    public static function searchCommanders(CardFormat $format, array $parsed, array $filters): Collection
    {
        $query = OracleCard::query();

        if ($parsed['set_code']) {
            $query->whereHas('defaults', fn (Builder $q) => $q->whereHas(
                'set',
                fn (Builder $sq) => $sq->where('code', $parsed['set_code'])
            ));
        }

        if ($parsed['collector_number']) {
            $query->whereHas(
                'defaults',
                fn (Builder $q) => $q->where('collector_number', $parsed['collector_number'])
            );
        }

        OracleNameSearch::applyMultiTableNameSegments($query, $parsed['normalized_name_segments']);

        if ($filters['exclude']) {
            $query->where('id', '!=', $filters['exclude']);
        }

        // Rule 0: skip commander-legality and format-legality filters when the user opts in.
        if (! $filters['rule0']) {
            $query->legalIn($format);
        }

        OracleNameSearch::applyNameRanking($query, $parsed['normalized_name_segments']);

        $candidates = $query->select('id', 'name', 'color_identity', 'searchable_name')
            ->with(['faces' => fn ($q) => $q->select('oracle_card_id', 'face_index', 'mana_cost', 'type_line', 'oracle_text', 'power', 'toughness', 'loyalty')
                ->orderBy('face_index')])
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        $bannedAsCommander = $filters['rule0'] ? [] : $format->rules()->bannedAsCommander();

        $shown = $candidates
            ->filter(fn (OracleCard $card) => self::passesCommanderFilters($card, $filters, $bannedAsCommander))
            ->take(self::RESULT_LIMIT)
            ->values();

        // Resolved AFTER the take(), so the lookup covers only the rows that
        // will actually be shown rather than the whole candidate pool.
        $matched = OracleNameSearch::resolveMatchedTranslations(
            $shown->mapWithKeys(fn (OracleCard $card) => [$card->id => (string) $card->searchable_name])->all(),
            $parsed['normalized_name_segments'],
            Auth::user()?->locale->value,
        );

        return $shown->map(fn (OracleCard $card) => self::mapCommanderCard($card, $matched[$card->id] ?? null));
    }

    /**
     * Check every face-based commander/companion/background/partner filter
     * against the in-memory `faces` collection of one candidate. Same
     * semantics as the previous SQL `whereHas` chain.
     *
     * @param  array<int, string>  $bannedAsCommander  Oracle names banned from the command zone in the active format.
     */
    private static function passesCommanderFilters(OracleCard $card, array $filters, array $bannedAsCommander): bool
    {
        // Background search has its own type-line filter — skip the
        // commander qualification check there. Rule 0 also skips it.
        if (! $filters['rule0'] && ! $filters['background'] && ! self::qualifiesAsCommander($card)) {
            return false;
        }
        if (in_array($card->name, $bannedAsCommander, true)) {
            return false;
        }
        if ($filters['partner'] && ! self::hasPartnerKeyword($card)) {
            return false;
        }
        if ($filters['doctors_companion'] && ! self::hasOracleTextContains($card, "Doctor's companion")) {
            return false;
        }
        if ($filters['friends_forever'] && ! self::hasOracleTextContains($card, 'Friends forever')) {
            return false;
        }
        if ($filters['background'] && ! self::hasTypeLineContains($card, 'Background')) {
            return false;
        }
        if ($filters['partner_type'] && ! self::hasOracleTextContains($card, "Partner—{$filters['partner_type']}")) {
            return false;
        }

        return true;
    }

    /**
     * Front face is a legendary creature (has power+toughness) OR any face
     * carries the explicit "can be your commander" override.
     */
    private static function qualifiesAsCommander(OracleCard $card): bool
    {
        $front = $card->faces->firstWhere('face_index', 0);
        $isLegendaryCreature = $front instanceof OracleCardFace
            && str_contains((string) $front->type_line, 'Legendary')
            && $front->power !== null
            && $front->toughness !== null;
        if ($isLegendaryCreature) {
            return true;
        }

        return $card->faces->contains(
            fn (OracleCardFace $face) => str_contains((string) $face->oracle_text, 'can be your commander')
        );
    }

    /**
     * Match the SQL `oracle_text REGEXP '\bPartner\b|Legendary partner'` —
     * the `\b` word-boundary excludes "Partner—X" / "Partner with X" forms,
     * which have their own filters and shouldn't fall through to plain
     * Partner.
     */
    private static function hasPartnerKeyword(OracleCard $card): bool
    {
        return $card->faces->contains(function (OracleCardFace $face): bool {
            $text = (string) $face->oracle_text;

            return preg_match('/\bPartner\b|Legendary partner/', $text) === 1;
        });
    }

    /** Any face's `oracle_text` contains the given substring (case-sensitive, like the SQL LIKE). */
    private static function hasOracleTextContains(OracleCard $card, string $needle): bool
    {
        return $card->faces->contains(
            fn (OracleCardFace $face) => str_contains((string) $face->oracle_text, $needle)
        );
    }

    /** Any face's `type_line` contains the given substring. */
    private static function hasTypeLineContains(OracleCard $card, string $needle): bool
    {
        return $card->faces->contains(
            fn (OracleCardFace $face) => str_contains((string) $face->type_line, $needle)
        );
    }

    /**
     * Transform an eager-loaded OracleCard into the commander response shape.
     *
     * Expects the `faces` relation to be loaded with at least
     * `oracle_card_id`, `face_index`, `mana_cost`, `type_line`, and `oracle_text`.
     *
     * @return array{id: string, name: string, color_identity: string|null, companion_type: string|null, partner_with_name: string|null, faces: array}
     */
    public static function mapCommanderCard(OracleCard $card, ?array $matchedTranslation = null): array
    {
        $allOracleText = $card->faces->pluck('oracle_text')->implode("\n");
        $frontTypeLine = $card->faces->first()?->type_line ?? '';
        $companion = self::resolveCompanionType($allOracleText, $frontTypeLine);

        return [
            'id' => $card->id,
            'name' => $card->name,
            'color_identity' => $card->color_identity,
            'companion_type' => $companion['type'],
            'partner_with_name' => $companion['partner_with_name'],
            'faces' => $card->faces->map(fn ($face) => [
                'type_line' => $face->type_line,
                'mana_cost' => $face->mana_cost,
            ])->values(),
            // Why this card is in the results when its English name plainly
            // does not match. Null when the English name explains it, which is
            // the overwhelming majority — see resolveMatchedTranslations().
            'matched_translation' => $matchedTranslation,
        ];
    }

    /**
     * Search for Oathbreaker command-zone cards (planeswalker or signature spell).
     *
     * Same two-phase strategy as {@see searchCommanders}: SQL handles
     * name + legality + cheap predicates; PHP handles the type-line check.
     *
     * @param  array{name_segments: string[], normalized_name_segments: string[], set_code: string|null, collector_number: string|null}|null  $parsed
     */
    public static function searchOathbreaker(
        CardFormat $format,
        ?array $parsed,
        string $type,
        ?string $colorIdentity,
        bool $rule0,
        ?string $exclude,
    ): Collection {
        if (! $parsed) {
            return collect();
        }

        $query = OracleCard::query();

        if ($parsed['set_code']) {
            $query->whereHas('defaults', fn (Builder $q) => $q->whereHas(
                'set',
                fn (Builder $sq) => $sq->where('code', $parsed['set_code'])
            ));
        }

        if ($parsed['collector_number']) {
            $query->whereHas(
                'defaults',
                fn (Builder $q) => $q->where('collector_number', $parsed['collector_number'])
            );
        }

        OracleNameSearch::applyMultiTableNameSegments($query, $parsed['normalized_name_segments']);

        if ($exclude) {
            $query->where('id', '!=', $exclude);
        }

        if (! $rule0) {
            $query->legalIn($format);
        }

        // Color identity check on signature spells: must be a subset of the
        // planeswalker's identity. Cheap on the indexed column, so it stays
        // in SQL.
        if ($type === 'spell' && $colorIdentity !== null) {
            foreach (['W', 'U', 'B', 'R', 'G'] as $color) {
                if (! str_contains($colorIdentity, $color)) {
                    $query->where(function (Builder $q) use ($color): void {
                        $q->whereNull('color_identity')
                            ->orWhere('color_identity', 'not like', "%$color%");
                    });
                }
            }
        }

        OracleNameSearch::applyNameRanking($query, $parsed['normalized_name_segments']);

        $candidates = $query->select('id', 'name', 'color_identity', 'searchable_name')
            ->with(['faces' => fn ($q) => $q->select('oracle_card_id', 'face_index', 'mana_cost', 'type_line', 'oracle_text', 'power', 'toughness', 'loyalty')
                ->orderBy('face_index')])
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        $matches = $candidates->filter(fn (OracleCard $card) => $type === 'planeswalker'
            ? self::isFrontFacePlaneswalker($card)
            : self::isFrontFaceInstantOrSorcery($card)
        );

        $shown = $matches
            ->take(self::RESULT_LIMIT)
            ->values();

        // Resolved AFTER the take(), so the lookup covers only the rows that
        // will actually be shown rather than the whole candidate pool.
        $matched = OracleNameSearch::resolveMatchedTranslations(
            $shown->mapWithKeys(fn (OracleCard $card) => [$card->id => (string) $card->searchable_name])->all(),
            $parsed['normalized_name_segments'],
            Auth::user()?->locale->value,
        );

        return $shown->map(fn (OracleCard $card) => self::mapCommanderCard($card, $matched[$card->id] ?? null));
    }

    /** Front face is a Planeswalker with loyalty (i.e. an actual walker, not a "is also" card). */
    private static function isFrontFacePlaneswalker(OracleCard $card): bool
    {
        $front = $card->faces->firstWhere('face_index', 0);

        return $front instanceof OracleCardFace
            && str_contains((string) $front->type_line, 'Planeswalker')
            && $front->loyalty !== null;
    }

    /** Front face is an Instant or Sorcery — the signature-spell type rule. */
    private static function isFrontFaceInstantOrSorcery(OracleCard $card): bool
    {
        $front = $card->faces->firstWhere('face_index', 0);
        if (! $front instanceof OracleCardFace) {
            return false;
        }
        $type = (string) $front->type_line;

        return str_contains($type, 'Instant') || str_contains($type, 'Sorcery');
    }

    /**
     * Determine the companion type from the combined oracle text and front-face type line.
     *
     * @return array{type: 'partner'|'partner_with'|'partner_type'|'friends_forever'|'doctors_companion'|'background'|null, partner_with_name: string|null}
     */
    public static function resolveCompanionType(string $oracleText, string $frontTypeLine): array
    {
        if (preg_match('/Choose a Background/i', $oracleText)) {
            return ['type' => 'background', 'partner_with_name' => null];
        }

        if (preg_match('/Partner with ([^\n(]+)/i', $oracleText, $matches)) {
            return ['type' => 'partner_with', 'partner_with_name' => trim($matches[1])];
        }

        if (preg_match('/Friends forever/i', $oracleText)) {
            return ['type' => 'friends_forever', 'partner_with_name' => null];
        }

        // Time Lord Doctors can have a Doctor's companion in the command zone.
        if ($frontTypeLine === 'Legendary Creature — Time Lord Doctor') {
            return ['type' => 'doctors_companion', 'partner_with_name' => null];
        }

        // "Partner—Survivors", "Partner—Character Select", etc.
        // These can only pair with other cards sharing the same typed partner tag.
        if (preg_match('/Partner\x{2014}([^\n(]+)/iu', $oracleText, $matches)) {
            return ['type' => 'partner_type', 'partner_with_name' => trim($matches[1])];
        }

        if (preg_match('/\bPartner\b|Legendary partner/i', $oracleText)) {
            return ['type' => 'partner', 'partner_with_name' => null];
        }

        return ['type' => null, 'partner_with_name' => null];
    }
}
