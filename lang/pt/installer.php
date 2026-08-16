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
    'title' => 'Instalar o SaniTube',
    'description' => 'Esta página existe apenas até a instalação terminar, e fecha-se sozinha quando isso acontece.',
    'last_attempt' => 'Onde a última tentativa parou',
    'token' => 'Token de instalação',
    'token_help' => 'Abra :path neste servidor e cole aqui o seu conteúdo. Só quem consegue ler esse ficheiro pode instalar o SaniTube — antes da instalação não existe nenhuma conta, portanto não há mais ninguém a quem perguntar.',
    'token_refused' => 'Esse token não corresponde ao que existe neste servidor.',
    'application' => 'Aplicação',
    'app_url' => 'Endereço a partir do qual esta instalação é servida',
    'database' => 'Base de dados',
    'db_connection' => 'Motor',
    'db_host' => 'Servidor',
    'db_port' => 'Porta',
    'db_database' => 'Nome da base de dados',
    'db_username' => 'Utilizador',
    'db_password' => 'Palavra-passe',
    'owner' => 'Conta proprietária',
    'owner_description' => 'A primeira conta, e a única que pode administrar tudo o resto. Não há página de registo: qualquer conta posterior é criada aqui ou a partir do terminal.',
    'name' => 'Nome',
    'email' => 'Endereço de e-mail',
    'password' => 'Palavra-passe',
    'confirm_password' => 'Confirmar palavra-passe',
    'submit' => 'Instalar',
    'configuration_written' => 'Definições de aplicação e base de dados escritas no .env, depois de uma cópia de segurança.',
    'configuration_failed' => 'O ficheiro .env não pôde ser escrito. Foi primeiro copiado e deixado como estava.',
    'completed' => 'O SaniTube está instalado. Inicie sessão com a conta proprietária que acabou de criar.',
];
