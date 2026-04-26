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

    'category_created' => 'Created new group ":group" for the deck ":deck".',

    'deck_created' => 'Deck ":name" has been created.',
    'deck_updated' => 'Deck ":name" has been updated.',
    'deck_deleted' => 'Deck ":name" has been deleted.',
    'deck_hero_changed' => 'Changed hero image of deck ":name" to ":card".',

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
