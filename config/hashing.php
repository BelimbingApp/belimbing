<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Argon2id is memory-hard and is the preferred password hashing algorithm.
    | The defaults below use OWASP's recommended minimum configuration.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 19456),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 2),
        // Allow cross-algorithm verification while legacy bcrypt hashes are
        // transparently upgraded after each user's successful login.
        'verify' => env('HASH_VERIFY', false),
    ],

    'rehash_on_login' => true,

];
