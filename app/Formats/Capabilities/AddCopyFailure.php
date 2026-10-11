<?php

namespace App\Formats\Capabilities;

/**
 * Reasons a card copy cannot be added to a deck under a given format's rules.
 *
 * A full deck is not one of them: adding past the format's maximum deck size
 * is allowed, and reported as a legality violation instead.
 */
enum AddCopyFailure: string
{
    case ExceedsMaxCopies = 'exceeds_max_copies';
    case ViolatesSingleton = 'violates_singleton';
    case NotInPool = 'not_in_pool';
    case ViolatesColorIdentity = 'violates_color_identity';
}
