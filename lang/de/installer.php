<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The web installer
|--------------------------------------------------------------------------
|
| Rendered before the asset pipeline is built and before the database has
| been reached, so every string here has to stand on its own with no screen
| around it to give it context.
|
*/

return [
    'title' => 'SaniTube installieren',
    'description' => 'Diese Seite besteht nur bis zum Abschluss der Installation und schließt sich danach von selbst.',
    'last_attempt' => 'Wo der letzte Versuch stehen blieb',
    'token' => 'Installationstoken',
    'token_help' => 'Öffnen Sie :path auf diesem Server und fügen Sie den Inhalt unten ein. Nur wer diese Datei lesen kann, darf SaniTube installieren — vor der Installation gibt es keine Konten, also niemanden sonst, den man fragen könnte.',
    'token_refused' => 'Dieses Token stimmt nicht mit dem auf diesem Server überein.',
    'application' => 'Anwendung',
    'app_url' => 'Adresse, unter der diese Installation ausgeliefert wird',
    'database' => 'Datenbank',
    'db_connection' => 'Engine',
    'db_host' => 'Host',
    'db_port' => 'Port',
    'db_database' => 'Datenbankname',
    'db_username' => 'Benutzer',
    'db_password' => 'Passwort',
    'owner' => 'Eigentümerkonto',
    'owner_description' => 'Das erste Konto und das einzige, das alles Weitere verwalten kann. Es gibt keine Registrierungsseite: jedes spätere Konto wird hier oder im Terminal angelegt.',
    'name' => 'Name',
    'email' => 'E-Mail-Adresse',
    'password' => 'Passwort',
    'confirm_password' => 'Passwort bestätigen',
    'submit' => 'Installieren',
    'configuration_written' => 'Anwendungs- und Datenbankeinstellungen wurden nach einer Sicherung in .env geschrieben.',
    'configuration_failed' => 'Die .env-Datei konnte nicht geschrieben werden. Sie wurde zuvor gesichert und unverändert gelassen.',
    'completed' => 'SaniTube ist installiert. Melden Sie sich mit dem soeben erstellten Eigentümerkonto an.',
];
