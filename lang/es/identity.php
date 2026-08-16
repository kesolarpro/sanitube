<?php

declare(strict_types=1);

return [
    'reset' => [
        'request_title' => 'Restablecer tu contraseña',
        'title' => 'Elige una contraseña nueva',
        'send' => 'Enviar un enlace',
        'submit' => 'Cambiar la contraseña',
        'new_password' => 'Contraseña nueva',
        'confirm_password' => 'Confirmar la contraseña nueva',
        'requested' => 'Si esa dirección tiene cuenta aquí, va en camino un enlace. Revisa tu bandeja de entrada.',
        'done' => 'Tu contraseña ha cambiado. Inicia sesión con la nueva.',
        'invalid' => 'Ese enlace ya no sirve. Pide uno nuevo.',
        'throttled' => 'Demasiadas peticiones. Inténtalo en :seconds segundos.',
    ],

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
