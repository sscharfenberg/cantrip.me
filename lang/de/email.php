<?php

return [

    'greeting' => 'Hallo!',
    'regards' => 'Mit freundlichen Grüßen,',
    'team' => 'Das Team von cantrip.me',
    'footer' => 'Alle Rechte vorbehalten.',
    'subcopy' => 'Wenn du Probleme beim Klicken der Schaltfläche ":actionText" hast, kopiere die folgende URL in deinen Webbrowser:',

    'verification' => [
        'subject' => 'E-Mail-Adresse bestätigen',
        'line1' => 'Bitte klicke auf die Schaltfläche, um deine E-Mail-Adresse zu bestätigen.',
        'action' => 'E-Mail-Adresse bestätigen',
        'line2' => 'Wenn du kein Benutzerkonto erstellt hast, ist keine weitere Aktion erforderlich.',
    ],

    'reset' => [
        'subject' => 'Passwort zurücksetzen',
        'line1' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passwortes für dein Konto erhalten haben.',
        'action' => 'Passwort zurücksetzen',
        'line2' => 'Dieser Link zum Zurücksetzen des Passwortes läuft in :count Minuten ab.',
        'line3' => 'Wenn du kein Zurücksetzen des Passwortes angefordert hast, ist keine weitere Aktion erforderlich.',
    ],

    'username' => [
        'subject' => 'Ihr Benutzername',
        'line1' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zur Erinnerung an deinen Benutzernamen erhalten haben.',
        'line2' => 'Dein Benutzername lautet: :name',
        'line3' => 'Wenn du diese Anfrage nicht gestellt hast, ist keine weitere Aktion erforderlich.',
    ],

    'scryfall_failure' => [
        'subject' => '[cantrip.me] Scryfall-Update fehlgeschlagen: :command',
        'intro' => 'Ein Scryfall-Update-Schritt ist auf :host fehlgeschlagen.',
        'command_label' => 'Fehlgeschlagener Befehl:',
        'exception_label' => 'Exception:',
        'message_label' => 'Nachricht:',
        'location_label' => 'Datei:',
        'time_label' => 'Zeitpunkt:',
        'closing' => 'Den vollständigen Stack-Trace siehst du im Scryfall-Log-Channel.',
    ],

];
