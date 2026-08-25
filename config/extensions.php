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
    | This optional per-deployment catalog pins familiar sources and copy. A
    | platform operator may also install a GitHub repository URL through
    | System → Software, where BLB validates placement, namespace, and every
    | Module manifest before migrations run. See the private-source guide.
    |
    */
    'catalog' => [
        // 'Ham' => [
        //     'repo' => '<private-blb-ham-repository-url>',
        //     'description' => 'Private Ham Extension.',
        // ],
    ],
];
