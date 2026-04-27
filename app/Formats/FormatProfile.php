<?php

namespace App\Formats;

use App\Enums\CardFormat;
use App\Formats\Capabilities\AddCopyFailure;
use App\Formats\Capabilities\AddCopyResult;
use App\Formats\Capabilities\CompanionPlacement;
use App\Models\OracleCard;

/**
 * A FormatProfile describes the construction rules of a Magic: The Gathering format.
 *
 * Legality of individual cards is DATA (the `legalities` pivot table populated from
 * Scryfall) and is NOT expressed here. This class only models rules that cannot be
 * answered by a simple pivot lookup: deck size, copy limits, singleton, commander,
 * color identity, companion placement, and pool overlays.
 *
 * Subclasses override only what differs from the Constructed defaults.
 */
abstract class FormatProfile
{
    /** Basic land names exempt from the singleton / max-copies rules. */
    public const BASIC_LANDS = [
        'Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes',
        'Snow-Covered Plains', 'Snow-Covered Island', 'Snow-Covered Swamp',
        'Snow-Covered Mountain', 'Snow-Covered Forest', 'Snow-Covered Wastes',
    ];

    public function __construct(public readonly CardFormat $format) {}

    /** Minimum cards in the main deck. */
    public function minDeckSize(): int
    {
        return 60;
    }

    /** Maximum cards in the main deck (null = unlimited). */
    public function maxDeckSize(): ?int
    {
        return null;
    }

    /** Maximum number of cards in the sideboard. */
    public function maxSideboardSize(): int
    {
        return 15;
    }

    /** Maximum copies of a non-basic card allowed in the deck. */
    public function maxCopies(): int
    {
        return 4;
    }

    /** Whether the format requires a designated commander. */
    public function requiresCommander(): bool
    {
        return false;
    }

    /** Maximum number of commanders allowed (1 = solo, 2 = partner pairs). */
    public function maxCommanders(): int
    {
        return 0;
    }

    /** Whether cards in the deck must obey the commander's color identity. */
    public function enforcesColorIdentity(): bool
    {
        return false;
    }

    /** Whether the format has a signature spell slot (Oathbreaker). */
    public function hasSignatureSpell(): bool
    {
        return false;
    }

    /** Where a companion sits (sideboard vs. outside-the-deck). */
    public function companionPlacement(): CompanionPlacement
    {
        return CompanionPlacement::Sideboard;
    }

    /** Whether the Magic "Companion" keyword mechanic is usable in this format. */
    public function allowsCompanion(): bool
    {
        return true;
    }

    /**
     * Whether the format uses the Wizards "Game Changer" list (the bracket
     * system's restricted-power overlay). Drives the per-card GC badge in
     * the deck UI; ignored when false. Currently only the official
     * Commander format opts in.
     */
    public function usesGameChangerList(): bool
    {
        return false;
    }

    /**
     * Oracle names of companions that are format-banned from the companion slot.
     *
     * @return array<int, string>
     */
    public function bannedAsCompanion(): array
    {
        return [];
    }

    /**
     * Pool overlay hook. Return false for cards that are legal per the pivot but
     * should still be excluded (house bans, Canadian Highlander points, etc.).
     *
     * Default implementation trusts the `legalities` pivot and returns true.
     */
    public function isInPool(OracleCard $card): bool
    {
        return true;
    }

    /**
     * Stateless check whether one more copy of `$card` may be added.
     *
     * Caller supplies the current copy count of this card and the current main
     * deck size so the profile does not need to touch the database.
     */
    public function canAddCopy(
        OracleCard $card,
        int $currentCopies,
        int $currentDeckSize,
    ): AddCopyResult {
        if (! $this->isInPool($card)) {
            return AddCopyResult::denied(AddCopyFailure::NotInPool);
        }

        if ($this->maxDeckSize() !== null && $currentDeckSize >= $this->maxDeckSize()) {
            return AddCopyResult::denied(AddCopyFailure::ExceedsDeckSize);
        }

        if ($this->isBasicLand($card)) {
            return AddCopyResult::allowed();
        }

        if ($card->hasUnlimitedCopiesRule()) {
            return AddCopyResult::allowed();
        }

        if ($this->maxCopies() === 1 && $currentCopies >= 1) {
            return AddCopyResult::denied(AddCopyFailure::ViolatesSingleton);
        }

        if ($currentCopies >= $this->maxCopies()) {
            return AddCopyResult::denied(AddCopyFailure::ExceedsMaxCopies);
        }

        return AddCopyResult::allowed();
    }

    protected function isBasicLand(OracleCard $card): bool
    {
        return in_array($card->name, self::BASIC_LANDS, true);
    }

    /**
     * Capability flags serialized for the frontend. Vue templates switch on these
     * flags rather than format names — keeps UI logic out of the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'minDeckSize' => $this->minDeckSize(),
            'maxDeckSize' => $this->maxDeckSize(),
            'maxSideboardSize' => $this->maxSideboardSize(),
            'maxCopies' => $this->maxCopies(),
            'isSingleton' => $this->maxCopies() === 1,
            'requiresCommander' => $this->requiresCommander(),
            'maxCommanders' => $this->maxCommanders(),
            'enforcesColorIdentity' => $this->enforcesColorIdentity(),
            'hasSignatureSpell' => $this->hasSignatureSpell(),
            'companionPlacement' => $this->companionPlacement()->value,
            'allowsCompanion' => $this->allowsCompanion(),
            'bannedAsCompanion' => $this->bannedAsCompanion(),
            'usesGameChangerList' => $this->usesGameChangerList(),
        ];
    }
}
