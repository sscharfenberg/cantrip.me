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
            'required' => 'Please enter the username.',
            'required_if' => 'Please enter the username.',
            'unique' => 'The username has already been taken',
            'min' => 'The username must have at least :min characters.',
        ],
        'email' => [
            'required' => 'Please enter the email address.',
            'email' => 'This does not seem to be a valid e-mail-address.',
            'unique' => 'This e-mail has already been taken.',
            'exists' => 'This e-mail does not exist in our records.',
        ],
        'password' => [
            'required' => 'Please enter the password.',
            'min' => 'The password can not be less than :min characters.',
            'entropy' => 'The password is not secure enough.',
            'current_password' => 'This password does not match our records.',
        ],
        'password_confirmation' => [
            'required' => 'Please confirm the password.',
            'same' => 'The password confirmation does not match.',
        ],
        'current_password' => [
            'required' => 'Please enter your current password.',
            'current_password' => 'This password does not match our records.',
        ],
        'container_name' => [
            'required' => 'Please enter the name of the container.',
        ],
        'container_type' => [
            'required' => 'Please choose the type of the container.',
        ],
        'container_type_other' => [
            'required' => 'Please enter the custom type of the container.',
        ],
        'default_card_id' => [
            'required' => 'Please select a card.',
        ],
        'amount' => [
            'required' => 'Please enter the number of cards.',
            'integer' => 'The amount must be a whole number.',
            'min' => 'The amount must be at least :min.',
            'max' => 'The amount must not exceed :max.',
        ],
        'language' => [
            'required' => 'Please select a language.',
        ],
        'format' => [
            'required' => 'Please select a format.',
            'enum' => 'Please select a valid format.',
        ],
        'file' => [
            'max' => 'The file must not be larger than :max MB.',
            'csv_not_parseable' => 'The file does not appear to be a valid CSV file.',
            'missing_headers' => 'The CSV file is missing required columns: :headers',
            'not_found' => 'The uploaded file could not be found. Please upload again.',
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
        'commander_id' => 'commander',
        'signature_spell_id' => 'signature spell',
        'companion_id' => 'companion',
        'deck_name' => 'deck name',
        'deck_description' => 'deck description',
    ],

];
