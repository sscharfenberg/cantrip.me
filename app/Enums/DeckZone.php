<?php

namespace App\Enums;

enum DeckZone: string
{
    case Main = 'main';
    case Side = 'side';
    case Maybe = 'maybe';
    case Command = 'command';
    case Companion = 'companion';
}
