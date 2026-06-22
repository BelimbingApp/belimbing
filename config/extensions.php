<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Installable private extensions
    |--------------------------------------------------------------------------
    |
    | Licensee extensions, keyed by the `extensions/{folder}` directory they
    | clone into. Each entry is a private nested git repo. Private repos are
    | cloned with the GitHub token stored per owner under GitHub Access
    | (System → Software → GitHub Access); the GitHub owner is parsed from the
    | repo URL, so the folder name may differ from the GitHub account.
    |
    | This is a per-deployment, curated list on purpose: there is no
    | "install from arbitrary URL" path, which would reopen a supply-chain
    | attack surface. See docs/guides/extensions/private-extension-repositories.md.
    |
    */
    'catalog' => [
        'kiat' => [
            'repo' => 'https://github.com/kiatng/blb-kiat',
            'description' => 'Kiat licensee extension.',
        ],
    ],
];
