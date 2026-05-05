<?php

namespace App\Enums;

enum CardCondition: string
{
    case Mint = 'mint';
    case NearMint = 'near_mint';
    case Excellent = 'excellent';
    case Good = 'good';
    case LightPlayed = 'light_played';
    case Played = 'played';
    case Poor = 'poor';
}
