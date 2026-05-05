<?php

namespace App\Enums\Scryfall;

enum ScryfallRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Special = 'special';
    case Mythic = 'mythic';
    case Bonus = 'bonus';
}
