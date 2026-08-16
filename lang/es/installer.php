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
    'title' => 'Instalar SaniTube',
    'description' => 'Esta página existe solo hasta que la instalación termina, y se cierra sola cuando lo hace.',
    'last_attempt' => 'Dónde se detuvo el último intento',
    'token' => 'Token de instalación',
    'token_help' => 'Abra :path en este servidor y pegue aquí su contenido. Solo quien pueda leer ese archivo puede instalar SaniTube: antes de la instalación no existe ninguna cuenta, así que no hay nadie más a quien preguntar.',
    'token_refused' => 'Ese token no coincide con el que hay en este servidor.',
    'application' => 'Aplicación',
    'app_url' => 'Dirección desde la que se sirve esta instalación',
    'database' => 'Base de datos',
    'db_connection' => 'Motor',
    'db_host' => 'Servidor',
    'db_port' => 'Puerto',
    'db_database' => 'Nombre de la base de datos',
    'db_username' => 'Usuario',
    'db_password' => 'Contraseña',
    'owner' => 'Cuenta propietaria',
    'owner_description' => 'La primera cuenta, y la única que puede administrar todo lo demás. No hay página de registro: cualquier cuenta posterior se crea aquí o desde el terminal.',
    'name' => 'Nombre',
    'email' => 'Correo electrónico',
    'password' => 'Contraseña',
    'confirm_password' => 'Confirmar contraseña',
    'submit' => 'Instalar',
    'configuration_written' => 'Ajustes de aplicación y base de datos escritos en .env, tras hacer una copia de seguridad.',
    'configuration_failed' => 'No se pudo escribir el archivo .env. Se hizo una copia primero y se dejó como estaba.',
    'completed' => 'SaniTube está instalado. Inicie sesión con la cuenta propietaria que acaba de crear.',
];
