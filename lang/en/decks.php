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
    'deck_visibility_public' => 'Deck ":name" is now public — anyone with the link can view it.',
    'deck_visibility_private' => 'Deck ":name" is now private — only you can view it.',
    'deck_state_planned' => 'Deck ":name" is now planned.',
    'deck_state_built' => 'Deck ":name" is now finished.',
    'deck_state_archived' => 'Deck ":name" has been archived.',

    'finalize' => [
        'flash_built' => 'Deck ":name" is finished.',
    ],

    'collection_mode' => [
        'promoted_flash' => 'Deck ":name" is now using per-copy tracking.',
        'cleared_flash' => 'All collection assignments cleared from ":name".',
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

];
