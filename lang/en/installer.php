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
    'title' => 'Install SaniTube',
    'description' => 'This page exists only until the installation is finished, and closes itself when it is.',
    'last_attempt' => 'Where the last attempt stopped',
    'token' => 'Installation token',
    'token_help' => 'Open :path on this server and paste what it contains below. Nobody but the person who can read that file may install this — before installation there are no accounts, so there is nobody else to ask.',
    'token_refused' => 'That token does not match the one on this server.',
    'application' => 'Application',
    'app_url' => 'Address this installation is served from',
    'database' => 'Database',
    'db_connection' => 'Engine',
    'db_host' => 'Host',
    'db_port' => 'Port',
    'db_database' => 'Database name',
    'db_username' => 'Username',
    'db_password' => 'Password',
    'owner' => 'Owner account',
    'owner_description' => 'The first account, and the one that can administer everything else. There is no sign-up page: every later account is created from here or from the terminal.',
    'name' => 'Name',
    'email' => 'Email address',
    'password' => 'Password',
    'confirm_password' => 'Confirm password',
    'submit' => 'Install',
    'configuration_written' => 'Wrote the application and database settings to .env, after backing it up.',
    'configuration_failed' => 'The .env file could not be written. It was backed up first and left as it was.',
    'completed' => 'SaniTube is installed. Sign in with the owner account you just created.',
];
