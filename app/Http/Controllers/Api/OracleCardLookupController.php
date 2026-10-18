<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LookupOracleCardsRequest;
use App\Services\OracleCardLookupService;
use Illuminate\Http\JsonResponse;

class OracleCardLookupController extends Controller
{
    /**
     * Search oracle cards for the welcome page's lookup.
     *
     * Public, like the card preview it leads to: each result carries the
     * printing whose `/cards/{printing}/preview` the detail modal opens.
     */
    public function search(LookupOracleCardsRequest $request): JsonResponse
    {
        return response()->json(OracleCardLookupService::search($request->validated('q')));
    }
}
