<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Zirda, the Dawnwaker — "Each permanent card in your starting deck has an
 * activated ability."
 *
 * Detection uses two passes on the oracle text:
 *  1. Strip reminder text (parentheses). A colon remaining on the printed
 *     lines denotes an activated ability (`Cost: Effect`). This catches
 *     mana abilities, tap abilities, loyalty abilities, and ninjutsu /
 *     crew / equip variants that inline their cost.
 *  2. Look for keyword-activated abilities that compress the cost behind a
 *     keyword ("Crew 3", "Cycling {2}", "Equip {1}"). These have no colon
 *     in their short form so the colon check misses them.
 *
 * Known limitation: the keyword list is not exhaustive (older keywords like
 * Transfigure, Transmute, Outlast) — add them if a real deck trips on them.
 */
final class ZirdaProfile extends CompanionProfile
{
    private const ACTIVATED_KEYWORDS = [
        'Crew',
        'Cycling',
        'Equip',
        'Ninjutsu',
        'Commander ninjutsu',
        'Channel',
        'Level up',
        'Morph',
        'Megamorph',
        'Reconfigure',
        'Unearth',
        'Outlast',
        'Scavenge',
        'Transmute',
        'Transfigure',
        'Reinforce',
        'Fortify',
    ];

    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'zirda';
    }

    /**
     * Flag each permanent card that lacks a detectable activated ability.
     * Non-permanents (Instant, Sorcery) are skipped.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if (! $this->isPermanent($oracle)) {
                continue;
            }
            if ($this->hasActivatedAbility($oracle)) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }

    /**
     * True when the card exposes an activated ability via printed cost (a
     * surviving `:` after reminder-text removal) or via one of the known
     * activated keyword abilities. See the class docblock for why the two
     * passes are both needed.
     */
    private function hasActivatedAbility(OracleCard $card): bool
    {
        $text = $this->oracleText($card);
        if ($text === '') {
            return false;
        }

        // Strip reminder text (parentheses, possibly multi-line).
        $stripped = preg_replace('/\s*\([^)]*\)/s', '', $text) ?? $text;

        if (str_contains($stripped, ':')) {
            return true;
        }

        foreach (self::ACTIVATED_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $stripped) === 1) {
                return true;
            }
        }

        return false;
    }
}
