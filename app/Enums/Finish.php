<?php

namespace App\Enums;

enum Finish: int
{
    case Nonfoil = 1;
    case Foil = 2;
    case Etched = 4;

    /**
     * Convert a Scryfall finishes array (e.g. ["nonfoil", "foil"]) to a bitmask.
     *
     * @param  array<string>  $finishes
     */
    public static function fromScryfallArray(array $finishes): int
    {
        $mask = 0;

        foreach ($finishes as $finish) {
            $case = self::tryFrom(self::scryfallNameToValue($finish));

            if ($case !== null) {
                $mask |= $case->value;
            }
        }

        return $mask;
    }

    /**
     * Check whether a bitmask includes this finish.
     */
    public function isPresentIn(int $mask): bool
    {
        return ($mask & $this->value) !== 0;
    }

    /**
     * The finish a copy of a printing gets: `$wanted` when the printing
     * (`$mask`) exists in it, otherwise the printing's first finish — nonfoil,
     * then foil, then etched — so a foil-only printing is never planned as a
     * nonfoil that does not exist. A mask without any known finish keeps
     * `$wanted`, or nonfoil.
     */
    public static function fitTo(int $mask, ?self $wanted = null): self
    {
        if ($wanted !== null && $wanted->isPresentIn($mask)) {
            return $wanted;
        }
        foreach (self::cases() as $case) {
            if ($case->isPresentIn($mask)) {
                return $case;
            }
        }

        return $wanted ?? self::Nonfoil;
    }

    /**
     * Lowercase label for frontend display and i18n keys (e.g. 'nonfoil', 'foil', 'etched').
     */
    public function label(): string
    {
        return strtolower($this->name);
    }

    /**
     * Resolve a Finish case from its lowercase label string.
     */
    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }

    /**
     * All lowercase label strings, for use in validation rules and frontend props.
     *
     * @return array<string>
     */
    public static function labels(): array
    {
        return array_map(fn (self $case) => $case->label(), self::cases());
    }

    /**
     * Decode a bitmask into an array of lowercase label strings.
     *
     * @return array<string>
     */
    public static function labelsFromMask(int $mask): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            if ($case->isPresentIn($mask)) {
                $labels[] = $case->label();
            }
        }

        return $labels;
    }

    /**
     * Map a Scryfall finish string to the corresponding enum integer value.
     */
    private static function scryfallNameToValue(string $name): int
    {
        return match ($name) {
            'nonfoil' => self::Nonfoil->value,
            'foil' => self::Foil->value,
            'etched' => self::Etched->value,
            default => 0,
        };
    }
}
