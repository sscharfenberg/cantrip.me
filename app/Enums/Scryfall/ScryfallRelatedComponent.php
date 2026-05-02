<?php

namespace App\Enums\Scryfall;

enum ScryfallRelatedComponent: string
{
    case Token = 'token';
    case MeldPart = 'meld_part';
    case MeldResult = 'meld_result';
    case ComboPiece = 'combo_piece';
}
