<?php

namespace App\Enums;

/**
 * Optional secondary classification of a deck_card row, orthogonal to
 * {@see DeckZone}. Most rows have role=null (mainboard / sideboard /
 * maybeboard cards). Special slots get a role:
 *
 *  - Commander       — the deck's primary commander.
 *  - Partner         — the second command-zone slot (Partner mechanic,
 *                      Choose-a-Background, Friends Forever — the
 *                      mechanic-specific label is rendered from the
 *                      card's type line, not stored here).
 *  - SignatureSpell  — Oathbreaker's spell slot.
 *  - Companion       — the Magic "Companion" sideboard card.
 *
 * Enforced as `UNIQUE(deck_id, role)` at the schema level so a deck
 * never has two commanders, two partners, two signature spells, or two
 * companions. Multiple NULL rows are allowed (mainboard / sideboard).
 */
enum DeckCardRole: string
{
    case Commander = 'commander';
    case Partner = 'partner';
    case SignatureSpell = 'signature_spell';
    case Companion = 'companion';

    /**
     * All command-zone roles. Used by query scopes / validators that
     * need to address "any card in the command zone" without enumerating
     * each role.
     *
     * @return array<DeckCardRole>
     */
    public static function commandZone(): array
    {
        return [self::Commander, self::Partner, self::SignatureSpell];
    }
}
