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
    'title' => 'Installa SaniTube',
    'description' => 'Questa pagina esiste solo fino al termine dell\'installazione, e poi si chiude da sola.',
    'last_attempt' => 'Dove si è fermato l\'ultimo tentativo',
    'token' => 'Token di installazione',
    'token_help' => 'Apri :path su questo server e incolla qui il suo contenuto. Solo chi può leggere quel file può installare SaniTube: prima dell\'installazione non esiste alcun account, quindi non c\'è nessun altro a cui chiedere.',
    'token_refused' => 'Questo token non corrisponde a quello presente su questo server.',
    'application' => 'Applicazione',
    'app_url' => 'Indirizzo da cui viene servita questa installazione',
    'database' => 'Database',
    'db_connection' => 'Motore',
    'db_host' => 'Host',
    'db_port' => 'Porta',
    'db_database' => 'Nome del database',
    'db_username' => 'Utente',
    'db_password' => 'Password',
    'owner' => 'Account proprietario',
    'owner_description' => 'Il primo account, e l\'unico che può amministrare tutto il resto. Non esiste una pagina di registrazione: ogni account successivo viene creato qui o dal terminale.',
    'name' => 'Nome',
    'email' => 'Indirizzo email',
    'password' => 'Password',
    'confirm_password' => 'Conferma password',
    'submit' => 'Installa',
    'configuration_written' => 'Impostazioni di applicazione e database scritte in .env, dopo il backup.',
    'configuration_failed' => 'Il file .env non è stato scritto. È stato prima salvato e lasciato invariato.',
    'completed' => 'SaniTube è installato. Accedi con l\'account proprietario appena creato.',
];
