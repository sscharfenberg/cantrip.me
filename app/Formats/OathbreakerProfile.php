<?php

namespace App\Formats;

/**
 * Oathbreaker: 60-card singleton with a planeswalker commander ("oathbreaker")
 * and an instant/sorcery signature spell bound to it.
 */
class OathbreakerProfile extends CommanderProfile
{
    public function minDeckSize(): int
    {
        return 60;
    }

    public function maxDeckSize(): ?int
    {
        return 60;
    }

    public function maxCommanders(): int
    {
        return 1;
    }

    public function hasSignatureSpell(): bool
    {
        return true;
    }

    public function allowsCompanion(): bool
    {
        return false;
    }

    public function bannedAsCompanion(): array
    {
        return [];
    }
}
