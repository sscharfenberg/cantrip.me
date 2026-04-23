<?php

namespace App\Companions;

use App\Models\OracleCard;
use Illuminate\Support\Collection;

/**
 * Registry of the ten Magic "Companion" keyword cards.
 *
 * Resolves by name rather than UUID so references survive Scryfall re-imports
 * that regenerate oracle card IDs.
 */
final class CompanionRegistry
{
    /** Oracle names of the ten Companion-keyword cards. */
    public const NAMES = [
        'Gyruda, Doom of Depths',
        'Jegantha, the Wellspring',
        'Kaheera, the Orphanguard',
        'Keruga, the Macrosage',
        'Lurrus of the Dream-Den',
        'Lutri, the Spellchaser',
        'Obosh, the Preypiercer',
        'Umori, the Collector',
        'Yorion, Sky Nomad',
        'Zirda, the Dawnwaker',
    ];

    /**
     * All ten companion oracle cards, with faces eager-loaded.
     *
     * Faces carry mana_cost, type_line and oracle_text — enough for the
     * picker tile UI without an extra trip to the DB.
     *
     * @return Collection<int, OracleCard>
     */
    public static function all(): Collection
    {
        return OracleCard::query()
            ->whereIn('name', self::NAMES)
            ->with(['faces' => fn ($q) => $q->orderBy('face_index')])
            ->get()
            ->sortBy(fn (OracleCard $card) => array_search($card->name, self::NAMES, true))
            ->values();
    }

    public static function isCompanion(OracleCard $card): bool
    {
        return in_array($card->name, self::NAMES, true);
    }
}
