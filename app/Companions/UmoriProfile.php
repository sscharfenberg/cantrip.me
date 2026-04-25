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
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'umori';
    }

    /**
     * Compute the running intersection of card types across all nonland
     * cards in main deck + sideboard. If it collapses to empty, report
     * every nonland deck_card row — the user needs to pick a shared type
     * and pruning which cards "are wrong" depends on that choice.
     *
     * A deck with zero nonland cards is vacuously legal.
     */
    public function validate(Deck $deck): ?array
    {
        $startingCards = $this->startingDeckCards($deck);

        $nonlandDeckCards = [];
        $shared = null;
        foreach ($startingCards as $deckCard) {
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
        foreach ($startingCards as $deckCard) {
            if ($this->isLand($deckCard->oracleCard)) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return ['card_ids' => $ids];
    }
}
