<?php

/*
 * Menu naming convention
 *
 *   Segments      lowercase, dotted, hyphens (not underscores) for multi-word
 *                 tokens, singular nouns for identifier segments. Labels can
 *                 stay plural prose; only the identifier segment is singular.
 *
 *   Item ID       <bucket>.<entity> or <bucket>.<subgroup>.<entity> (depth as
 *                 the structure requires). The first segment is always a live
 *                 top-level bucket: admin, operations, commerce, people.
 *                 Examples: admin.system.log, commerce.marketplace.ebay-setting,
 *                 admin.employee, operations.quality.ncr.
 *
 *   Permission    <item-id>.<action> where action is one of:
 *                     view, list, manage, create, update, delete
 *                 Example: admin.system.log.list. A permission key without a
 *                 corresponding menu item is allowed but should still follow
 *                 <bucket>.<entity>...<action>.
 *
 *   Bucket        Top-level buckets are declared by their domain anchor:
 *   declarations
 *                     admin       app/Base/Menu/Config/menu.php   (this file)
 *                     commerce    app/Modules/Commerce/Config/menu.php
 *                     operations  app/Modules/Operation/Config/menu.php
 *                     people      app/Modules/People/Config/menu.php
 *
 *                 Leaf modules / packages declare only leaves and intermediate
 *                 containers, never a root. Extensions follow the same shape:
 *                 extensions/<vendor>/Config/menu.php may declare top-level
 *                 buckets the same way core domains do.
 *
 *                 Reservations for future buckets without a domain home yet
 *                 (finance, procurement, maintenance, production) live below
 *                 as commented-out entries until each gets its own anchor.
 *
 *   Diagnosing    Every MenuItem carries `sourceModule` and `sourceFile`
 *   "where did    populated by MenuDiscoveryService. To answer "where is this
 *    this come    declared?":
 *    from?"
 *                     Menu Inspector page  /admin/system/menu-inspector
 *                                          lists every registered item with
 *                                          its source, parent, permission,
 *                                          condition, and computed visibility
 *
 *                     Debug tooltip        when APP_DEBUG=true, hovering a
 *                                          menu item reveals its id, source
 *                                          module, and source file path
 *
 *                 No permanent live-sidebar marker for extensions — diagnosis
 *                 is one click (or one hover) away when you want it, and out
 *                 of the way when you don't.
 */

return [
    // Fallback policy when menu items define permission but no authorization adapter is installed.
    // Menu visibility is UI-only; route/middleware authorization remains authoritative.
    'permissioned_items_without_authorizer' => 'allow',

    'items' => [
        [
            'id' => 'admin',
            'label' => 'Administration',
            'icon' => 'heroicon-o-cog-6-tooth',
        ],

        // Reserved future buckets — graduate to live entries (in their domain
        // anchor) once each has two or more meaningful submodules. Empty
        // containers do not render.
        // [ 'id' => 'finance',     'label' => 'Finance',     'icon' => 'heroicon-o-banknotes' ],
        // [ 'id' => 'procurement', 'label' => 'Procurement', 'icon' => 'heroicon-o-truck' ],
        // [ 'id' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'heroicon-o-wrench' ],
        // [ 'id' => 'production',  'label' => 'Production',  'icon' => 'heroicon-o-cube' ],

        [
            'id' => 'admin.system',
            'label' => 'System',
            'icon' => 'heroicon-o-server-stack',
            'parent' => 'admin',
        ],

        // System subgroups.
        [
            'id' => 'admin.system.diagnostics',
            'label' => 'Diagnostics',
            'icon' => 'heroicon-o-signal',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.database',
            'label' => 'Database',
            'icon' => 'heroicon-o-circle-stack',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.integrations',
            'label' => 'Integrations',
            'icon' => 'heroicon-o-link',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.update',
            'label' => 'Update',
            'icon' => 'heroicon-o-arrow-down-circle',
            'parent' => 'admin.system',
        ],
    ],
];
