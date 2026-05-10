<?php

namespace App\Services\Scryfall;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ScryfallService
{
    /**
     * Create a pre-configured HTTP client with Scryfall's required headers.
     */
    protected function http(): PendingRequest
    {
        return Http::withHeaders(config('cantrip.scryfall.header'));
    }

    /**
     * Resolve the live table name to its live or shadow counterpart based
     * on the import mode. Lets each service write the same insert query
     * regardless of whether the orchestrator is rebuilding a shadow set
     * (UpdateEverything) or the command was invoked standalone (live).
     */
    protected function tableName(string $live, bool $shadow): string
    {
        return $shadow ? ShadowTableRegistry::shadow($live) : $live;
    }
}
