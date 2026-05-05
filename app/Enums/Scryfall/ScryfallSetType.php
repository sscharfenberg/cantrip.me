<?php

namespace App\Enums\Scryfall;

enum ScryfallSetType: string
{
    case Core = 'core';
    case Expansion = 'expansion';
    case Masters = 'masters';
    case Eternal = 'eternal';
    case Alchemy = 'alchemy';
    case Masterpiece = 'masterpiece';
    case Arsenal = 'arsenal';
    case FromTheVault = 'from_the_vault';
    case Spellbook = 'spellbook';
    case PremiumDeck = 'premium_deck';
    case DuelDeck = 'duel_deck';
    case DraftInnovation = 'draft_innovation';
    case TreasureChest = 'treasure_chest';
    case Commander = 'commander';
    case Planechase = 'planechase';
    case Archenemy = 'archenemy';
    case Vanguard = 'vanguard';
    case Funny = 'funny';
    case Starter = 'starter';
    case Box = 'box';
    case Promo = 'promo';
    case Token = 'token';
    case Memorabilia = 'memorabilia';
    case Minigame = 'minigame';
}
