<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Installable private extensions
    |--------------------------------------------------------------------------
    |
    | Private Extensions, keyed by their PascalCase `app/Extensions/{Extension}`
    | source directory. Each entry is a private nested Git repository. Repos are
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
        // 'Ham' => [
        //     'repo' => '<private-blb-ham-repository-url>',
        //     'description' => 'Private Ham Extension.',
        // ],
    ],
];
