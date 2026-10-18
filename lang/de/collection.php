<?php

return [

    'card_added' => ':name wurde zu deiner Sammlung hinzugefügt.',
    'card_added_to_container' => ':name wurde zu :container hinzugefügt.',
    'amount_changed' => 'Anzahl von :name wurde auf :amount geändert.',
    'card_updated' => ':name wurde aktualisiert.',
    'card_deleted' => ':amount× :name wurde aus deiner Sammlung entfernt.',
    'cards_moved' => ':number Kartenstapel erfolgreich nach :container verschoben.',
    'cards_deleted' => ':stacks Kartenstapel mit insgesamt :cards Karten gelöscht.',
    'cards_mass_moved' => ':number Karten von \':source\' nach \':target\' verschoben.',
    'cardstack_unclaimed' => '{0} :name war für kein Deck reserviert.|{1} Reservierung von :name für :count Deck aufgehoben.|[2,*] Reservierung von :name für :count Decks aufgehoben.',
    'cardstack_removed_from_decks' => '{1} Aus der Deckliste entfernt.|[2,*] Aus :count Decklisten entfernt.',
    'cardstack_moved_to' => 'Nach „:container“ verschoben.',
    'collection_deleted' => 'Deine Sammlung wurde gelöscht.',
    'unsorted' => 'Unsortiert',
    'container_created' => 'Container ":name" wurde erstellt.',
    'container_updated' => 'Container ":name" wurde aktualisiert.',
    'container_deleted' => 'Container ":name" wurde gelöscht.',
    'container_pruned' => ':count Karten wurden aus ":name" entfernt.',

    'errors' => [
        'cannot_move_claimed_stack' => 'Dieser Kartenstapel ist einem Deck zugeordnet und kann nicht verschoben werden. Hebe die Zuordnung zuerst auf.',
        'amount_out_of_range' => 'Ein Kartenstapel enthält zwischen 1 und :max Karten.',
        'container_not_found' => 'Diesen Container gibt es nicht (mehr).',
        'finish_not_available_for_card' => 'Die gewählte Ausführung ist für diese Karte nicht verfügbar. Verfügbar: :available.',
    ],

];
