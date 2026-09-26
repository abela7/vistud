<?php

use Laravel\Fortify\Features;

/*
| Fortify: web sessions, two-factor authentication and password confirmation
| (ADR 0003 §10.3). Accounts are invite-only (D4), so registration is off.
| Views are off until work package WP6 supplies them; with views off,
| Fortify registers only its POST endpoints.
*/

return [

    'guard' => 'web',

    'middleware' => ['web'],

    'auth_middleware' => 'auth',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'views' => false,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => null,
    ],

    'paths' => [],

    // After logout, go straight to the login screen instead of bouncing
    // through "/" (which sends guests there anyway).
    'redirects' => [
        'logout' => '/login',
        // Land on the login screen, which already shows the status message.
        // The default with views off is "/", and that bounce drops the message.
        'password-reset' => '/login',
    ],

    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],

];
