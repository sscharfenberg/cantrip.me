<?php

namespace App\Enums;

/**
 * Supported source formats for deck CSV imports.
 *
 * Distinct from {@see ImportSource} (collection-side) because deck rows
 * map to a different shape — deck_cards quantity / zone / category /
 * commander role, not card-stack condition / finish / language.
 */
enum DeckImportSource: string
{
    case Cantrip = 'cantrip';
    case Archidekt = 'archidekt';
}
