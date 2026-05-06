<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'name' => [
            'required' => 'Bitte gib den Benutzernamen an.',
            'required_if' => 'Bitte gib den Benutzernamen an.',
            'unique' => 'Dieser Benutzername ist bereits vergeben.',
            'min' => 'Der Benutzername muss mindestens :min Zeichen enthalten.',
        ],
        'email' => [
            'required' => 'Bitte gib die E-Mail-Adresse an.',
            'email' => 'Dies scheint keine gültige E-Mail-Adresse zu sein.',
            'unique' => 'Diese E-Mail-Adresse wird bereits verwendet.',
            'exists' => 'Diese E-Mail-Adresse existiert nicht.',
        ],
        'password' => [
            'required' => 'Bitte gib das Passwort an.',
            'min' => 'Das Passwort muss mindestens :min Zeichen enthalten.',
            'entropy' => 'Das Passwort ist nicht sicher genug.',
            'current_password' => 'Dieses Passwort entspricht nicht unseren Aufzeichnungen.',
        ],
        'password_confirmation' => [
            'required' => 'Bitte bestätige das Passwort.',
            'same' => 'Die Passwort Bestätigung entspricht nicht dem eigentlichen Passwort.',
        ],
        'current_password' => [
            'required' => 'Bitte gib das aktuelle Passwort an.',
            'current_password' => 'Dieses Passwort entspricht nicht unseren Aufzeichnungen.',
        ],
        'container_name' => [
            'required' => 'Bitte gib den Namen des Containers an.',
        ],
        'container_type' => [
            'required' => 'Bitte wähle den Typ des Containers.',
        ],
        'container_type_other' => [
            'required' => 'Bitte gib den Sonstigen Typ des Containers an.',
        ],
        'default_card_id' => [
            'required' => 'Bitte wähle eine Karte aus.',
        ],
        'amount' => [
            'required' => 'Bitte gib die Anzahl der Karten an.',
            'integer' => 'Die Anzahl muss eine ganze Zahl sein.',
            'min' => 'Die Anzahl muss mindestens :min betragen.',
            'max' => 'Die Anzahl darf :max nicht überschreiten.',
        ],
        'language' => [
            'required' => 'Bitte wähle eine Sprache aus.',
        ],
        'format' => [
            'required' => 'Bitte wähle ein Format aus.',
            'enum' => 'Bitte wähle ein gültiges Format aus.',
        ],
        'file' => [
            'max' => 'Die Datei darf nicht größer als :max MB sein.',
            'csv_not_parseable' => 'Die Datei scheint keine gültige CSV-Datei zu sein.',
            'missing_headers' => 'Der CSV-Datei fehlen erforderliche Spalten: :headers',
            'not_found' => 'Die hochgeladene Datei wurde nicht gefunden. Bitte erneut hochladen.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'commander_id' => 'Commander',
        'signature_spell_id' => 'Signaturzauber',
        'companion_id' => 'Gefährte',
        'deck_name' => 'Deckname',
        'deck_description' => 'Deckbeschreibung',
    ],

];
