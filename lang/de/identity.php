<?php

declare(strict_types=1);

return [

    'auth' => [
        'sign_in' => 'Anmelden',
        'sign_out' => 'Abmelden',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'remember_me' => 'Angemeldet bleiben',
        'failed' => 'Diese Zugangsdaten stimmen mit keinem Konto überein.',
        'throttled' => 'Zu viele Anmeldeversuche. Versuchen Sie es in :seconds Sekunden erneut.',
        'deactivated' => 'Dieses Konto wurde deaktiviert.',
        'forbidden' => 'Ihre Rolle erlaubt diese Aktion nicht.',
    ],

    'roles' => [
        'owner' => 'Eigentümer',
        'admin' => 'Administrator',
        'member' => 'Mitglied',
    ],

];
