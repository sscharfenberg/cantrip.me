<?php

namespace App\Formats;

use App\Enums\CardFormat;
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

    /**
     * Duel Commander maintains its own banlist that distinguishes between
     * "banned in the deck entirely" (handled by the legalities pivot) and
     * "banned as commander but allowed in the 99" — the latter has no
     * counterpart in Scryfall's data, so it lives here.
     *
     * Source: https://www.duelcommander.com/banlist/
     */
    public function bannedAsCommander(): array
    {
        if ($this->format !== CardFormat::Duel) {
            return [];
        }

        return [
            'Ajani, Nacatl Pariah',
            'Arahbo, Roar of the World',
            'Breya, Etherium Shaper',
            'Derevi, Empyrial Tactician',
            'Dihada, Binder of Wills',
            'Edgar Markov',
            'Edric, Spymaster of Trest',
            'Emry, Lurker of the Loch',
            'Geist of Saint Traft',
            'Minsc & Boo, Timeless Heroes',
            'Najeela, the Blade-Blossom',
            'Old Stickfingers',
            'Oloro, Ageless Ascetic',
            'Urza, Lord High Artificer',
            'Vial Smasher the Fierce',
            'Winota, Joiner of Forces',
        ];
    }

    /**
     * Wizards' bracket / Game Changer system applies only to the base
     * Commander format. Variants that share this profile (Brawl, Predh,
     * Pauper Commander, Duel) use their own ban lists and don't reference
     * the GC overlay.
     */
    public function usesGameChangerList(): bool
    {
        return $this->format === CardFormat::Commander;
    }
}
