<?php

namespace App\Enums\Scryfall;

enum ScryfallCardLayout: string
{
    case Normal = 'normal';
    case Split = 'split';
    case Flip = 'flip';
    case Transform = 'transform';
    case ModalDfc = 'modal_dfc';
    case Meld = 'meld';
    case Leveler = 'leveler';
    case ClassLayout = 'class';
    case CaseLayout = 'case';
    case Saga = 'saga';
    case Adventure = 'adventure';
    case Mutate = 'mutate';
    case Prototype = 'prototype';
    case Battle = 'battle';
    case Planar = 'planar';
    case Scheme = 'scheme';
    case Vanguard = 'vanguard';
    case Token = 'token';
    case DoubleFacedToken = 'double_faced_token';
    case Emblem = 'emblem';
    case Augment = 'augment';
    case Host = 'host';
    case ArtSeries = 'art_series';
    case ReversibleCard = 'reversible_card';
}
