<?php

return [

    'view' => [
        'flash' => [
            'success' => 'Default deck view updated.',
        ],
    ],

    'sort' => [
        'flash' => [
            'success' => 'Default deck sort updated.',
        ],
    ],

    'collection_integration' => [
        'flash' => [
            'success' => 'Collection integration preference updated.',
        ],
    ],

    'category_created' => 'Created new group ":group" for the deck ":deck".',

    'deck_created' => 'Deck ":name" has been created.',
    'deck_updated' => 'Deck ":name" has been updated.',
    'deck_deleted' => 'Deck ":name" has been deleted.',
    'deck_hero_changed' => 'Changed hero image of deck ":name" to ":card".',
    'deck_visibility_public' => 'Deck ":name" is now public - anyone with the link can view it.',
    'deck_visibility_private' => 'Deck ":name" is now private - only you can view it.',
    'deck_state_planned' => 'Deck ":name" is now planned.',
    'deck_state_built' => 'Deck ":name" is now finished.',
    'deck_state_archived' => 'Deck ":name" has been archived.',

    'bulk_claim' => [
        'flash' => 'Claims persisted for deck ":name".',
    ],

    'add_all_to_collection' => [
        'flash_success' => 'All cards from deck ":name" have been added to your collection.',
    ],

    'collection_mode' => [
        'set_flash' => 'Collection tracking for ":name" is now set to ":mode".',
        'modes' => [
            'A' => 'off',
            'B' => 'implicit',
            'C' => 'explicit',
        ],
    ],

    'companion' => [
        'errors' => [
            'not_allowed_in_format' => 'Companions are not allowed in this format.',
            'not_a_companion' => 'This card is not a companion.',
            'banned_in_format' => 'This companion is banned in this format.',
            'already_commander' => 'This card is already a commander on this deck.',
            'outside_color_identity' => "This companion is outside the commanders' color identity.",
        ],
    ],

    'import' => [
        'default_deck_name' => 'Imported deck',
        'default_deck_name_with_timestamp' => 'Imported deck :timestamp',
    ],

];
