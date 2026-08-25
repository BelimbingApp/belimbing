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
    | The Software screen also discovers candidates from trusted owners: every
    | owner with a stored GitHub Access token is asked for repositories marked
    | with the `belimbing-extension` topic (ExtensionCatalogDiscovery). This
    | catalog is therefore a pin/override layer — an entry here always wins
    | over a discovered candidate with the same key (e.g. to pin a repo URL
    | or description). There is still no "install from arbitrary URL" path,
    | which would reopen a supply-chain attack surface.
    | See docs/guides/extensions/private-extension-repositories.md.
    |
    */
    'catalog' => [
        // 'Ham' => [
        //     'repo' => '<private-blb-ham-repository-url>',
        //     'description' => 'Private Ham Extension.',
        // ],
    ],
];
