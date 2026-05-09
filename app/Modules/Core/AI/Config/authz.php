<?php
$agentOperatorCapabilities = [
    'admin.ai.chat-attachment.manage',
    'admin.ai.agent.view',
    'admin.ai.agent.execute',
    'admin.ai.tool.active-page-snapshot.view',
    'admin.ai.tool.delegation-status.execute',
    'admin.ai.tool.document-analysis.execute',
    'admin.ai.tool.guide.execute',
    'admin.ai.tool.image-analysis.execute',
    'admin.ai.tool.memory-get.execute',
    'admin.ai.tool.memory-search.execute',
    'admin.ai.tool.navigate.execute',
    'admin.ai.tool.notification.execute',
    'admin.ai.tool.read.execute',
    'admin.ai.tool.search.execute',
    'admin.ai.tool.system-info.execute',
    'admin.ai.tool.web-fetch.execute',
    'admin.ai.tool.web-search.execute',
    'admin.ai.tool.agent-list.execute',
    'admin.ai.tool.workspace.view',
];

$agentPowerUserAdditionalCapabilities = [
    'admin.ai.chat-model.manage',
    'admin.ai.tool.artisan.execute',
    'admin.ai.tool.bash.execute',
    'admin.ai.tool.browser.execute',
    'admin.ai.tool.browser-evaluate.execute',
    'admin.ai.tool.delegate.execute',
    'admin.ai.tool.edit-data.execute',
    'admin.ai.tool.edit-file.execute',
    'admin.ai.tool.edit.execute',
    'admin.ai.tool.message.execute',
    'admin.ai.tool.query-data.execute',
    'admin.ai.tool.read-file.execute',
    'admin.ai.tool.schedule.execute',
    'admin.ai.tool.search-files.execute',
    'admin.ai.tool.ticket-update.execute',
    'admin.ai.tool.workspace.manage',
    'admin.ai.tool.write-js.execute',
];

$capabilities = array_values(array_unique([
    ...$agentOperatorCapabilities,
    ...$agentPowerUserAdditionalCapabilities,

    'messaging.account.manage',
    'messaging.account.grant',
    'messaging.account.revoke',
    'messaging.whatsapp.send',
    'messaging.whatsapp.react',
    'messaging.whatsapp.media',
    'messaging.telegram.send',
    'messaging.telegram.react',
    'messaging.telegram.edit',
    'messaging.telegram.delete',
    'messaging.telegram.poll',
    'messaging.linkedin.send',
    'messaging.signal.send',
    'messaging.imessage.send',
    'messaging.slack.send',
    'messaging.email.send',
    'messaging.sms.send',
    'messaging.any.search',

    // Grants access to Lara provisioning and activation.
    'admin.ai.lara.manage',
    // Grants access to Lara task-model selection and recommendations.
    'admin.ai.task-model.manage',
    // Grants access to provider catalog, credentials, and model sync flows.
    'admin.ai.provider.manage',
    // Grants access to AI pricing override management.
    'admin.ai.pricing-override.manage',
    // Grants access to AI tool catalog and per-tool workspaces.
    'admin.ai.tool.manage',
    // Grants access to the operator-facing AI diagnostics surfaces:
    // the control plane itself plus standalone run drill-down pages.
    'admin.ai.control-plane.view',
]));

return [
    'domains' => [
        'ai' => 'AI and agent capabilities',
        'messaging' => 'Multi-channel messaging capabilities',
    ],

    'capabilities' => [
        ...$capabilities,
    ],

    'roles' => [
        'agent_operator' => [
            'name' => 'Agent Operator',
            'description' => 'View and execute agents with basic tool access.',
            'capabilities' => $agentOperatorCapabilities,
        ],
        'agent_power_user' => [
            'name' => 'Agent Power User',
            'description' => 'Full agent tool access including artisan, bash, delegation, scheduling, media analysis, and JS execution.',
            'capabilities' => [
                ...$agentOperatorCapabilities,
                ...$agentPowerUserAdditionalCapabilities,
            ],
        ],
        'ai_operator' => [
            'name' => 'AI Operator',
            'description' => 'Access to Lara setup, task models, providers, tools, and operator diagnostics without broad system administration rights.',
            'capabilities' => [
                'admin.ai.lara.manage',
                'admin.ai.task-model.manage',
                'admin.ai.provider.manage',
                'admin.ai.pricing-override.manage',
                'admin.ai.tool.manage',
                'admin.ai.control-plane.view',
            ],
        ],
        'messaging_reader' => [
            'name' => 'Messaging Reader',
            'description' => 'Read-only access to messaging - search conversations across channels.',
            'capabilities' => [
                'messaging.any.search',
            ],
        ],
        'messaging_responder' => [
            'name' => 'Messaging Responder',
            'description' => 'Agent that responds to inbound messages - send and react on configured channels.',
            'capabilities' => [
                'messaging.whatsapp.send',
                'messaging.whatsapp.react',
                'messaging.telegram.send',
                'messaging.telegram.react',
                'messaging.slack.send',
                'messaging.email.send',
                'messaging.any.search',
            ],
        ],
        'messaging_operator' => [
            'name' => 'Messaging Operator',
            'description' => 'Full messaging power on granted channels - send, react, edit, delete, polls, and media.',
            'capabilities' => [
                'messaging.whatsapp.send',
                'messaging.whatsapp.react',
                'messaging.whatsapp.media',
                'messaging.telegram.send',
                'messaging.telegram.react',
                'messaging.telegram.edit',
                'messaging.telegram.delete',
                'messaging.telegram.poll',
                'messaging.linkedin.send',
                'messaging.signal.send',
                'messaging.imessage.send',
                'messaging.slack.send',
                'messaging.email.send',
                'messaging.sms.send',
                'messaging.any.search',
            ],
        ],
        'messaging_admin' => [
            'name' => 'Messaging Admin',
            'description' => 'Supervisor role for managing channel accounts and agent access grants.',
            'capabilities' => [
                'messaging.account.manage',
                'messaging.account.grant',
                'messaging.account.revoke',
            ],
        ],
    ],
];
