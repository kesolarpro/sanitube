<?php

declare(strict_types=1);

return [
    'reset' => [
        'request_title' => 'Reset your password',
        'title' => 'Choose a new password',
        'send' => 'Send a reset link',
        'submit' => 'Change password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm new password',
        'requested' => 'If that address has an account here, a reset link is on its way. Check your inbox.',
        'done' => 'Your password has been changed. Sign in with the new one.',
        'invalid' => 'That reset link is no longer usable. Ask for a new one.',
        'throttled' => 'Too many requests. Try again in :seconds seconds.',
    ],

    'auth' => [
        'sign_in' => 'Sign in',
        'sign_out' => 'Sign out',
        'email' => 'Email',
        'password' => 'Password',
        'remember_me' => 'Stay signed in',
        'failed' => 'Those credentials do not match our records.',
        'throttled' => 'Too many sign-in attempts. Try again in :seconds seconds.',
        'deactivated' => 'This account has been deactivated.',
        'forbidden' => 'Your role does not allow this action.',
    ],

    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'member' => 'Member',
    ],

];
