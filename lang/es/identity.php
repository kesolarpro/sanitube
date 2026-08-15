<?php

declare(strict_types=1);

return [

    'auth' => [
        'sign_in' => 'Iniciar sesión',
        'sign_out' => 'Cerrar sesión',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'remember_me' => 'Mantener la sesión iniciada',
        'failed' => 'Esas credenciales no coinciden con nuestros registros.',
        'throttled' => 'Demasiados intentos de inicio de sesión. Inténtalo de nuevo en :seconds segundos.',
        'deactivated' => 'Esta cuenta ha sido desactivada.',
        'forbidden' => 'Tu rol no permite esta acción.',
    ],

    'roles' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'member' => 'Miembro',
    ],

];
