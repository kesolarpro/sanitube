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
    'title' => 'Installer SaniTube',
    'description' => 'Cette page n\'existe que jusqu\'à la fin de l\'installation, et se referme d\'elle-même ensuite.',
    'last_attempt' => 'Où la dernière tentative s\'est arrêtée',
    'token' => 'Jeton d\'installation',
    'token_help' => 'Ouvrez :path sur ce serveur et collez son contenu ci-dessous. Seule la personne capable de lire ce fichier peut installer SaniTube — avant l\'installation il n\'existe aucun compte, donc personne d\'autre à qui demander.',
    'token_refused' => 'Ce jeton ne correspond pas à celui présent sur ce serveur.',
    'application' => 'Application',
    'app_url' => 'Adresse depuis laquelle cette installation est servie',
    'database' => 'Base de données',
    'db_connection' => 'Moteur',
    'db_host' => 'Hôte',
    'db_port' => 'Port',
    'db_database' => 'Nom de la base',
    'db_username' => 'Utilisateur',
    'db_password' => 'Mot de passe',
    'owner' => 'Compte propriétaire',
    'owner_description' => 'Le premier compte, et le seul à pouvoir administrer tout le reste. Il n\'y a pas de page d\'inscription : tout compte ultérieur est créé ici ou depuis le terminal.',
    'name' => 'Nom',
    'email' => 'Adresse e-mail',
    'password' => 'Mot de passe',
    'confirm_password' => 'Confirmer le mot de passe',
    'submit' => 'Installer',
    'configuration_written' => 'Paramètres d\'application et de base de données écrits dans .env, après sauvegarde.',
    'configuration_failed' => 'Le fichier .env n\'a pas pu être écrit. Il a d\'abord été sauvegardé et laissé tel quel.',
    'completed' => 'SaniTube est installé. Connectez-vous avec le compte propriétaire que vous venez de créer.',
];
