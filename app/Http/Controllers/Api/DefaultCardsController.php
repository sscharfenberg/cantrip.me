<?php

namespace App\Http\Controllers\Api;

use App\Enums\Finish;
use App\Http\Controllers\Controller;
use App\Services\CardSearchParser;
use App\Services\DefaultCardSearchService;
use App\Services\OracleNameSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DefaultCardsController extends Controller
{
    /**
     * Search default_cards by name (and optionally set code) and return id +
     * art crop. Used by the container hero-image picker.
     *
     * Supports "set:xxx" and "number:xxx" tokens in the query string, e.g.:
     *   "sol ring set:lea"  →  name LIKE %sol% AND name LIKE %ring% AND set.code = 'lea'
     *   "set:lea"           →  all cards from set 'lea'
     *   "number:123"        →  collector_number = '123'
     */
    public function artCropSearch(Request $request): JsonResponse
    {
        $parsed = CardSearchParser::parse(trim($request->query('q', '')));
        if (! $parsed) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $built = DefaultCardSearchService::buildQuery($parsed);
        if ($built === null) {
            return response()->json(['total' => 0, 'results' => []]);
        }
        $base = $built['query'];
        $base->whereNotNull('default_cards.art_crop');

        // Total comes from the same filtered base before ORDER BY/LIMIT —
        // cheap because phase 1 already narrowed to <= ORACLE_PREFILTER_LIMIT
        // oracles (or to a set/cn-bounded slice) so the printings count is a
        // small indexed lookup.
        $total = (clone $base)->count();

        $rows = DefaultCardSearchService::orderAndFetch($base, $parsed['normalized_name_segments'], [
            'default_cards.id',
            'default_cards.name AS card_name',
            'default_cards.art_crop',
            'sets.name AS set_name',
            'sets.code AS set_code',
            'sets.path AS set_path',
            'artists.name AS artist_name',
        ]);

        $results = $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'name' => $row->card_name,
            'art_crop' => $row->art_crop,
            'artist' => $row->artist_name,
            'set' => $row->set_code !== null ? [
                'name' => $row->set_name,
                'code' => $row->set_code,
                'path' => $row->set_path,
            ] : null,
        ])->values();

        return response()->json(['total' => $total, 'results' => $results]);
    }

    /**
     * Search default_cards by name (and optionally set code) and return card
     * face images. Used by the card-stack add flow.
     */
    public function searchCardImage(Request $request): JsonResponse
    {
        $parsed = CardSearchParser::parse(trim($request->query('q', '')));
        if (! $parsed) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $built = DefaultCardSearchService::buildQuery($parsed);
        if ($built === null) {
            return response()->json(['total' => 0, 'results' => []]);
        }
        $base = $built['query'];
        // Drop printings with no face image — the UI can't render them and
        // they'd leave a clickable empty cell in the results grid. Mirrors
        // the `whereNotNull('art_crop')` filter in `artCropSearch`.
        $base->whereNotNull('default_cards.card_image_0');

        $total = (clone $base)->count();

        $rows = DefaultCardSearchService::orderAndFetch($base, $parsed['normalized_name_segments'], [
            'default_cards.id',
            'default_cards.oracle_id',
            'default_cards.name AS card_name',
            'default_cards.card_image_0',
            'default_cards.card_image_1',
            'default_cards.collector_number',
            'default_cards.finishes',
            'sets.name AS set_name',
            'sets.code AS set_code',
            'sets.path AS set_path',
            'artists.name AS artist_name',
        ]);

        // Per-oracle translation badge — only present when the English
        // searchable_name didn't already explain the match (e.g. typing
        // "Blitz" surfaces Aether Flash via DE "Ätherblitz" but leaves
        // Blitzball Stadium silent). The auth()->user() preference
        // breaks ties when multiple langs match.
        $matchedTranslations = OracleNameSearch::resolveMatchedTranslations(
            $built['oracle_searchable_names'],
            $parsed['normalized_name_segments'],
            Auth::user()?->locale->value,
        );

        $results = $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'name' => $row->card_name,
            'card_image_0' => $row->card_image_0,
            'card_image_1' => $row->card_image_1,
            'artist' => $row->artist_name,
            'cn' => $row->collector_number,
            'finishes' => Finish::labelsFromMask((int) $row->finishes),
            'set' => $row->set_code !== null ? [
                'name' => $row->set_name,
                'code' => $row->set_code,
                'path' => $row->set_path,
            ] : null,
            'matched_translation' => $matchedTranslations[$row->oracle_id] ?? null,
        ])->values();

        return response()->json(['total' => $total, 'results' => $results]);
    }
}
