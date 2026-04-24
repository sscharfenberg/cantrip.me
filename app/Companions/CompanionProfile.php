<?php

namespace App\Companions;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use Illuminate\Support\Collection;

/**
 * Base class for the deckbuilding restriction attached to each Companion card.
 *
 * Each subclass implements {@see validate()}, scanning the deck's main zone
 * and returning a violation payload when the rule is broken:
 *
 *  - Per-card restrictions (Gyruda, Lurrus, …) return `['card_ids' => [...]]`
 *    so the legality panel can name the offending cards.
 *  - Deck-size restrictions (Yorion) return `['current' => N, 'min' => M]`.
 *  - A clean deck returns `null`.
 *
 * The validator wraps whatever comes back as `type: 'companion_restriction'`
 * with the companion's `messageKey()` so the shape matches the existing
 * DeckValidator violation contract 1-to-1.
 *
 * Scope:
 *  - Main deck only. Commanders and sideboard are not part of the "starting
 *    deck" the restrictions are phrased around. (Yorion adds commanders back
 *    to match the deck-size checks already in DeckValidator.)
 *  - `quantity` only matters for the count-based Yorion check; per-card
 *    restrictions run once per deck_card row.
 *
 * Assumes `deckCards.oracleCard.faces` and `commanders` are already loaded on
 * the deck so this class performs no queries.
 *
 * @phpstan-type CompanionViolation array{card_ids: array<int, string>}|array{current: int, min: int}
 */
abstract class CompanionProfile
{
    /** Card types considered "permanent" for Lurrus / Zirda. */
    protected const PERMANENT_TYPES = ['Artifact', 'Creature', 'Enchantment', 'Land', 'Planeswalker', 'Battle'];

    /**
     * i18n key suffix under `pages.deck.legality.companion_restriction.*`,
     * used by the legality panel to render the right message per companion.
     */
    abstract public function messageKey(): string;

    /**
     * Validate the deck against this companion's restriction.
     *
     * @return CompanionViolation|null
     */
    abstract public function validate(Deck $deck): ?array;

    /**
     * All deck_cards in the main zone.
     *
     * @return Collection<int, DeckCard>
     */
    protected function mainDeckCards(Deck $deck): Collection
    {
        return $deck->deckCards->filter(
            fn (DeckCard $deckCard): bool => $deckCard->zone === DeckZone::Main
        )->values();
    }

    /**
     * Unique card types spanning all faces, e.g. `['Creature', 'Land']` for
     * Dryad Arbor. Supertypes (Basic, Legendary, Snow, World, Tribal) are
     * stripped; subtypes (everything after the em-dash) are not included.
     *
     * @return array<int, string>
     */
    protected function cardTypes(OracleCard $card): array
    {
        $supertypes = ['Basic', 'Legendary', 'Snow', 'World', 'Ongoing', 'Host', 'Elite'];
        $types = [];

        foreach ($card->faces as $face) {
            $line = (string) ($face->type_line ?? '');
            if ($line === '') {
                continue;
            }
            $left = preg_split('/\s[—–]\s/u', $line, 2)[0];
            foreach (preg_split('/\s+/', trim($left)) ?: [] as $token) {
                if ($token === '' || in_array($token, $supertypes, true)) {
                    continue;
                }
                $types[$token] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * Subtypes spanning all faces. The segment after the " — " dash in
     * type_line.
     *
     * @return array<int, string>
     */
    protected function subtypes(OracleCard $card): array
    {
        $subtypes = [];

        foreach ($card->faces as $face) {
            $line = (string) ($face->type_line ?? '');
            $parts = preg_split('/\s[—–]\s/u', $line, 2);
            if (! is_array($parts) || count($parts) !== 2) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($parts[1])) ?: [] as $token) {
                if ($token === '') {
                    continue;
                }
                $subtypes[$token] = true;
            }
        }

        return array_keys($subtypes);
    }

    /**
     * True when any face of the card carries the Land card type. Dual-type
     * cards like Dryad Arbor (Land Creature) count as lands here.
     */
    protected function isLand(OracleCard $card): bool
    {
        return in_array('Land', $this->cardTypes($card), true);
    }

    /**
     * True when any face's card types include a permanent type (see
     * {@see self::PERMANENT_TYPES}). Instants and sorceries return false.
     */
    protected function isPermanent(OracleCard $card): bool
    {
        return array_intersect(self::PERMANENT_TYPES, $this->cardTypes($card)) !== [];
    }

    /**
     * True when any face of the card is a Creature.
     */
    protected function isCreature(OracleCard $card): bool
    {
        return in_array('Creature', $this->cardTypes($card), true);
    }

    /**
     * Oracle text of all faces joined with a line break. Missing faces become
     * the empty string.
     */
    protected function oracleText(OracleCard $card): string
    {
        return $card->faces
            ->sortBy('face_index')
            ->map(fn (OracleCardFace $face): string => (string) ($face->oracle_text ?? ''))
            ->implode("\n");
    }

    /**
     * Mana costs for each face of the card, with empty costs filtered out.
     * Cards like "Lightning Bolt" return `['{R}']`, "Fire // Ice" returns
     * `['{1}{R}', '{1}{U}']`, lands return `[]`.
     *
     * @return array<int, string>
     */
    protected function manaCosts(OracleCard $card): array
    {
        return $card->faces
            ->sortBy('face_index')
            ->map(fn (OracleCardFace $face): string => (string) ($face->mana_cost ?? ''))
            ->filter(fn (string $cost): bool => $cost !== '')
            ->values()
            ->all();
    }
}
