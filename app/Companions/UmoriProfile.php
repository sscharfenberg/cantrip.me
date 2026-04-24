<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Umori, the Collector — "All nonland cards in your starting deck share a
 * card type."
 *
 * No stored "chosen type" — the restriction is satisfied whenever the
 * intersection of nonland card types is non-empty. When it is empty, every
 * nonland card is reported: the user has to pick the shared type by deleting
 * cards, and naming one specifically would be misleading.
 */
final class UmoriProfile extends CompanionProfile
{
    public function messageKey(): string
    {
        return 'umori';
    }

    public function validate(Deck $deck): ?array
    {
        $mainCards = $this->mainDeckCards($deck);

        $nonlandDeckCards = [];
        $shared = null;
        foreach ($mainCards as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($this->isLand($oracle)) {
                continue;
            }
            $nonlandDeckCards[] = $deckCard;
            $types = $this->cardTypes($oracle);
            $shared = $shared === null ? $types : array_values(array_intersect($shared, $types));
            if ($shared === []) {
                break;
            }
        }

        if ($nonlandDeckCards === [] || $shared !== []) {
            return null;
        }

        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            if ($this->isLand($deckCard->oracleCard)) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return ['card_ids' => $ids];
    }
}
