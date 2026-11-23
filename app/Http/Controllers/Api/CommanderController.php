<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CommandZoneSearchRequest;
use App\Services\CardSearchParser;
use App\Services\CommandZoneService;
use Illuminate\Http\JsonResponse;

class CommanderController extends Controller
{
    /**
     * Search for cards that can be a commander.
     *
     * A card qualifies when at least one of its faces is either:
     *  - a legendary creature (type_line contains "Legendary" and has power + toughness), or
     *  - has "can be your commander" in its oracle text.
     *
     * Supports "set:xxx" and "number:xxx" tokens via CardSearchParser.
     *
     * Required query parameters:
     *  - `format=<key>` — CardFormat enum value; filters to cards legal/restricted in this format.
     *
     * Optional query parameters:
     *  - `rule0=1`              — skip commander-legality filters (any oracle card matches).
     *  - `partner=1`            — restrict to cards with the Partner keyword.
     *  - `friends_forever=1`    — restrict to cards with "Friends forever".
     *  - `doctors_companion=1`  — restrict to cards with "Doctor's companion".
     *  - `background=1`         — restrict to cards with the Background subtype.
     *  - `partner_type=<type>`  — restrict to cards with "Partner—<type>" (e.g. "Survivors").
     *  - `exclude=<uuid>`       — exclude a specific oracle card (e.g. the already-selected commander).
     *
     * When a companion filter is active (`friends_forever`, `doctors_companion`,
     * or `partner_type`), the `q` parameter may be omitted to list all matching
     * cards — these pools are small enough to display without a search query.
     */
    public function search(CommandZoneSearchRequest $request): JsonResponse
    {
        $format = CardFormat::tryFrom($request->query('format', ''));

        if (! $format) {
            return response()->json([]);
        }

        $filters = [
            'rule0' => $request->boolean('rule0'),
            'partner' => $request->boolean('partner'),
            'friends_forever' => $request->boolean('friends_forever'),
            'doctors_companion' => $request->boolean('doctors_companion'),
            'background' => $request->boolean('background'),
            'partner_type' => $request->query('partner_type'),
            'exclude' => $request->query('exclude'),
        ];

        $hasCompanionFilter = $filters['friends_forever']
            || $filters['doctors_companion']
            || $filters['partner_type'];

        $parsed = CardSearchParser::parse(trim($request->query('q', '')));

        // Allow empty query when a small-pool companion filter narrows the results.
        if (! $parsed && ! $hasCompanionFilter) {
            return response()->json([]);
        }

        $parsed ??= [
            'name_segments' => [],
            'normalized_name_segments' => [],
            'set_code' => null,
            'collector_number' => null,
        ];

        return response()->json(CommandZoneService::searchCommanders($format, $parsed, $filters));
    }

    /**
     * Search for Oathbreaker command-zone cards (planeswalker or signature spell).
     *
     * Required query parameters:
     *  - `format=<key>` — CardFormat enum value.
     *  - `type=planeswalker|spell` — which slot to search for.
     *
     * Optional query parameters:
     *  - `q=<query>`      — search query (supports "set:xxx" and "number:xxx" tokens).
     *  - `ci=<letters>`   — planeswalker's color identity for spell filtering (e.g. "WUB").
     *  - `rule0=1`        — skip legality filters.
     *  - `exclude=<uuid>` — exclude a specific oracle card.
     */
    public function searchOathbreaker(CommandZoneSearchRequest $request): JsonResponse
    {
        $format = CardFormat::tryFrom($request->query('format', ''));

        if (! $format) {
            return response()->json([]);
        }

        $type = $request->query('type', '');

        if (! in_array($type, ['planeswalker', 'spell'], true)) {
            return response()->json([]);
        }

        $parsed = CardSearchParser::parse(trim($request->query('q', '')));

        if (! $parsed) {
            return response()->json([]);
        }

        return response()->json(
            CommandZoneService::searchOathbreaker(
                $format,
                $parsed,
                $type,
                $request->query('ci'),
                $request->boolean('rule0'),
                $request->query('exclude'),
            )
        );
    }
}
