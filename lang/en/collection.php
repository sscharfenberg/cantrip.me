<?php

return [

    'card_added' => ':name has been added to your collection.',
    'card_added_to_container' => ':name has been added to :container.',
    'amount_changed' => ':name amount has been updated to :amount.',
    'card_updated' => ':name has been updated.',
    'card_deleted' => ':amount× :name has been removed from your collection.',
    'cards_moved' => ':number card stacks successfully moved to :container.',
    'cards_deleted' => 'Deleted :stacks card stacks with :cards cards total.',
    'cards_mass_moved' => ':number cards moved from \':source\' to \':target\'.',
    'cards_mass_move_claimed_stayed' => '{1} :number card stayed in \':source\' because a deck claims it.|[2,*] :number cards stayed in \':source\' because decks claim them.',
    'cardstack_unclaimed' => '{0} :name was not reserved by any deck.|{1} Unclaimed :name from :count deck.|[2,*] Unclaimed :name from :count decks.',
    'cardstack_removed_from_decks' => '{1} Removed it from the deck list.|[2,*] Removed it from :count deck lists.',
    'cardstack_moved_to' => 'Moved it to \':container\'.',
    'collection_deleted' => 'Your collection has been deleted.',
    'unsorted' => 'Unsorted',
    'container_created' => 'Container ":name" has been created.',
    'container_updated' => 'Container ":name" has been updated.',
    'container_deleted' => 'Container ":name" has been deleted.',
    'container_pruned' => ':count cards have been removed from ":name".',

    'errors' => [
        'cannot_move_claimed_stack' => 'This card stack is claimed by a deck and cannot be moved. Unclaim it first.',
        'cannot_change_amount_of_claimed_stack' => 'This card stack is claimed by a deck, so its amount cannot change. Unclaim it first.',
        'amount_out_of_range' => 'A card stack holds between 1 and :max cards.',
        'container_not_found' => 'That container does not exist (any more).',
        'finish_not_available_for_card' => 'The selected finish is not available for this card. Available: :available.',
    ],

];
