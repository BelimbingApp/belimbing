<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.info', 'System Info', 'heroicon-o-information-circle', parent: 'admin.system', route: 'admin.system.info.index', permission: 'admin.system.info.view'),
        $item('admin.system.settings', 'System Settings', 'heroicon-o-cog-6-tooth', parent: 'admin.system', route: 'admin.system.settings.index', permission: 'admin.system.settings.manage'),
        $item('admin.system.localization', 'Localization', 'heroicon-o-language', parent: 'admin.system', route: 'admin.system.localization.index', permission: 'admin.system.localization.manage'),
        $item('admin.system.ui-reference', 'UI Reference', 'heroicon-o-adjustments-horizontal', parent: 'admin.system', route: 'admin.system.ui-reference.index', permission: 'admin.system.ui-reference.view'),
        $item('admin.system.integration-parameters', 'Integration Secrets', 'heroicon-o-key', parent: 'admin.system.integrations', route: 'admin.system.integration-parameters.index', permission: 'admin.system.integration-parameters.manage'),
        $item('admin.system.email', 'Email', 'heroicon-o-paper-airplane', parent: 'admin.system.integrations', route: 'admin.system.email.index', permission: 'admin.system.email.manage'),
        $item('admin.system.test-transport', 'Test Transport', 'heroicon-o-play', parent: 'admin.system.integrations', route: 'admin.system.test-transport.index', permission: 'admin.system.test-transport.view'),
        $item('admin.system.menu-inspector', 'Menu Inspector', 'heroicon-o-magnifying-glass', parent: 'admin.system.diagnostics', route: 'admin.system.menu-inspector.index', permission: 'admin.system.menu-inspector.view'),
    ],
];
