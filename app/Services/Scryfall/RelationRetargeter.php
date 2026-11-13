<?php

namespace App\Services\Scryfall;

use App\Services\CardNameNormalizer;

/**
 * Picks a printing in `default_cards` for a related card whose own
 * printing is not there — an `all_parts` edge Scryfall points at a
 * foreign-language token, an excluded layout, a variant the bulk skipped.
 * {@see DefaultCardsService::flushRelationsBuffer()} has only the related
 * card's name and type line to go on.
 *
 * A name alone used to be enough to pick "the" printing, but names are not
 * unique: there are 21 Spirit token oracles, 8 Dragon ones, and a card can
 * share its name with an art-series "Card" or a token copy of itself. So
 * the candidates are narrowed step by step and only a single remaining
 * oracle is accepted:
 *
 *   1. oracles with that name and that type line, or — when the type line
 *      matches none (it can differ in form) — every oracle with the name;
 *   2. if several remain, those printed in the source card's set or in its
 *      token set (`t` + set code), where its tokens live.
 *
 * Anything still ambiguous is dropped rather than pointed at an arbitrary
 * token with the wrong power, toughness or colour.
 *
 * The edge then points at that card's printing in the source card's set
 * or token set when there is one — the deck view shows the token printing
 * that matches the card's — and at its lowest-id printing otherwise.
 */
final class RelationRetargeter
{
    /** @var array<string, array<string, string>> "name|type line" → oracle id → printing id */
    private array $byNameAndType = [];

    /** @var array<string, array<string, string>> name → oracle id → printing id */
    private array $byName = [];

    /** @var array<string, array<string, string>> "name|set code" → oracle id → its printing there */
    private array $bySet = [];

    /**
     * Takes one row per oracle and set, with a stable printing of it.
     *
     * @param  iterable<object{oracle_id: string, searchable_name: string, type_line: ?string, set_code: string, default_card_id: string}>  $printings
     */
    public function __construct(iterable $printings)
    {
        foreach ($printings as $row) {
            $name = $row->searchable_name;
            $this->keepLowest($this->byName[$name], $row->oracle_id, $row->default_card_id);
            $this->keepLowest($this->byNameAndType["$name|$row->type_line"], $row->oracle_id, $row->default_card_id);
            $this->keepLowest($this->bySet["$name|$row->set_code"], $row->oracle_id, $row->default_card_id);
        }
    }

    /**
     * The printing to point the edge at, or null when there is none or no
     * single right one.
     *
     * @param  string|null  $name  The related card's name, from `all_parts`.
     * @param  string|null  $typeLine  The related card's type line, from `all_parts`.
     * @param  string  $sourceSet  Set code of the card the edge starts at.
     */
    public function retarget(?string $name, ?string $typeLine, string $sourceSet): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $name = CardNameNormalizer::normalize($name);

        $nearby = ($this->bySet["$name|$sourceSet"] ?? []) + ($this->bySet["$name|t$sourceSet"] ?? []);
        $candidates = $this->byNameAndType["$name|$typeLine"] ?? $this->byName[$name] ?? [];
        if (count($candidates) > 1) {
            $candidates = array_intersect_key($candidates, $nearby);
        }
        if (count($candidates) !== 1) {
            return null;
        }

        return $nearby[array_key_first($candidates)] ?? reset($candidates);
    }

    /**
     * @param  array<string, string>|null  $map
     */
    private function keepLowest(?array &$map, string $oracleId, string $printingId): void
    {
        if (! isset($map[$oracleId]) || strcmp($printingId, $map[$oracleId]) < 0) {
            $map[$oracleId] = $printingId;
        }
    }
}
