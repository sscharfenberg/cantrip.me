<?php

namespace App\Services\Scryfall\OracleTagSyncs;

use App\Services\Scryfall\OracleTagsService;

/**
 * Plain "is this card tagged with otag:X" → boolean column sync.
 * Replaces the legacy `OracleTagsService::TAG_TO_COLUMN` static map
 * with a typed instance that participates in the same orchestrator
 * loop as the more elaborate {@see FetchPatternOracleTagSync}.
 *
 * Add a new boolean tag by registering one of these in
 * {@see OracleTagsService} — no parsing,
 * no per-card derivation needed.
 */
class BooleanOracleTagSync extends OracleTagSync
{
    public function __construct(
        public readonly string $tag,
        public readonly string $column,
    ) {}

    public function tag(): string
    {
        return $this->tag;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function clearValue(): mixed
    {
        return false;
    }

    /**
     * Every card returned by the search matched the tag, so the
     * column flips to `true` unconditionally — there's nothing in
     * the payload to inspect.
     *
     * @param  array<string, mixed>  $card
     */
    public function deriveValue(array $card): mixed
    {
        return true;
    }
}
