<?php

return [

    'view' => [
        'flash' => [
            'success' => 'Standard-Deckansicht aktualisiert.',
        ],
    ],

    'sort' => [
        'flash' => [
            'success' => 'Standard-Decksortierung aktualisiert.',
        ],
    ],

    'collection_integration' => [
        'flash' => [
            'success' => 'Sammlungs-Integration aktualisiert.',
        ],
    ],

    'category_created' => 'Neue Gruppe ":group" für das Deck ":deck" erstellt.',

    'deck_created' => 'Deck ":name" wurde erstellt.',
    'deck_updated' => 'Deck ":name" wurde aktualisiert.',
    'deck_deleted' => 'Deck ":name" wurde gelöscht.',
    'deck_hero_changed' => 'Bannerbild des Decks ":name" wurde auf ":card" geändert.',
    'deck_visibility_public' => 'Deck ":name" ist jetzt öffentlich - jeder mit dem Link kann es ansehen.',
    'deck_visibility_private' => 'Deck ":name" ist jetzt privat - nur du kannst es ansehen.',
    'deck_state_planned' => 'Deck ":name" ist jetzt geplant.',
    'deck_state_built' => 'Deck ":name" ist jetzt fertig.',
    'deck_state_archived' => 'Deck ":name" wurde archiviert.',

    'finalize' => [
        'flash_built' => 'Deck ":name" ist fertig.',
    ],

    'add_all_to_collection' => [
        'flash_success' => 'Alle Karten aus Deck „:name" wurden zur Sammlung hinzugefügt.',
    ],

    'collection_mode' => [
        'set_flash' => 'Sammlungs-Tracking für „:name" ist jetzt :mode.',
        'modes' => [
            'A' => 'aus',
            'B' => 'implizit',
            'C' => 'explizit',
        ],
    ],

    'companion' => [
        'errors' => [
            'not_allowed_in_format' => 'Gefährten sind in diesem Format nicht erlaubt.',
            'not_a_companion' => 'Diese Karte ist kein Gefährte.',
            'banned_in_format' => 'Dieser Gefährte ist in diesem Format verboten.',
            'already_commander' => 'Diese Karte ist bereits ein Commander dieses Decks.',
            'outside_color_identity' => 'Dieser Gefährte liegt außerhalb der Farbidentität der Commander.',
        ],
    ],

    'import' => [
        'default_deck_name' => 'Importiertes Deck',
        'default_deck_name_with_timestamp' => 'Importiertes Deck :timestamp',
    ],

];
