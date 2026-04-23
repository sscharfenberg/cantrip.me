<?php

namespace App\Formats;

use App\Formats\Capabilities\CompanionPlacement;

/**
 * 100-card singleton Commander rules.
 *
 * Also covers Duel Commander, Predh, Pauper Commander, and Brawl — rules are
 * identical, only the card pool (legalities pivot) differs.
 */
class CommanderProfile extends FormatProfile
{
    public function minDeckSize(): int
    {
        return 100;
    }

    public function maxDeckSize(): ?int
    {
        return 100;
    }

    public function maxSideboardSize(): int
    {
        return 0;
    }

    public function maxCopies(): int
    {
        return 1;
    }

    public function requiresCommander(): bool
    {
        return true;
    }

    public function maxCommanders(): int
    {
        return 2;
    }

    public function enforcesColorIdentity(): bool
    {
        return true;
    }

    public function companionPlacement(): CompanionPlacement
    {
        return CompanionPlacement::Outside;
    }

    public function bannedAsCompanion(): array
    {
        return ['Lutri, the Spellchaser'];
    }
}
