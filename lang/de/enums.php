<?php

/**
 * PHP-side mirror of the Vue-i18n `enums.*` block in
 * resources/app/lang/de.json, used wherever Laravel's `__()` /
 * `trans()` helpers need a human-readable enum label server-side
 * (e.g. PDF rendering via dompdf). Keep this file in sync with the
 * frontend JSON when adding or renaming enum cases.
 */
return [
    'container_type' => [
        'binder' => 'Ordner',
        'deckbox' => 'Deckbox',
        'display' => 'Display',
        'box' => 'Karton',
        'other' => 'Sonstiges',
        'cube' => 'Cube',
        'tin' => 'Booster Tin',
        'Toploader' => 'Toploader',
    ],
];
