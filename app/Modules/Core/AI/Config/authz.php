<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$agentOperatorCapabilities = [
    'ai.chat_attachments.manage',
    'ai.agent.view',
    'ai.agent.execute',
    'ai.tool_active_page_snapshot.view',
    'ai.tool_delegation_status.execute',
    'ai.tool_document_analysis.execute',
    'ai.tool_guide.execute',
    'ai.tool_image_analysis.execute',
    'ai.tool_memory_get.execute',
    'ai.tool_memory_search.execute',
    'ai.tool_navigate.execute',
    'ai.tool_notification.execute',
    'ai.tool_query_data.execute',
    'ai.tool_system_info.execute',
    'ai.tool_web_fetch.execute',
    'ai.tool_web_search.execute',
    'ai.tool_agent_list.execute',
    'ai.tool_workspace.view',
];

$agentPowerUserAdditionalCapabilities = [
    'ai.chat_model.manage',
    'ai.tool_artisan.execute',
    'ai.tool_bash.execute',
    'ai.tool_browser.execute',
    'ai.tool_browser_evaluate.execute',
    'ai.tool_delegate.execute',
    'ai.tool_edit_data.execute',
    'ai.tool_message.execute',
    'ai.tool_schedule.execute',
    'ai.tool_ticket_update.execute',
    'ai.tool_edit_file.execute',
    'ai.tool_workspace.manage',
    'ai.tool_write_js.execute',
];

return [
    'domains' => [
        'ai' => 'AI and agent capabilities',
        'messaging' => 'Multi-channel messaging capabilities',
    ],

    'capabilities' => [
        'ai.chat_attachments.manage',
        'ai.chat_model.manage',
        'ai.agent.view',
        'ai.agent.execute',
        'ai.tool_active_page_snapshot.view',
        'ai.tool_artisan.execute',
        'ai.tool_bash.execute',
        'ai.tool_browser.execute',
        'ai.tool_browser_evaluate.execute',
        'ai.tool_delegate.execute',
        'ai.tool_delegation_status.execute',
        'ai.tool_edit_data.execute',
        'ai.tool_guide.execute',
        'ai.tool_memory_get.execute',
        'ai.tool_memory_search.execute',
        'ai.tool_navigate.execute',
        'ai.tool_notification.execute',
        'ai.tool_query_data.execute',
        'ai.tool_schedule.execute',
        'ai.tool_system_info.execute',
        'ai.tool_web_fetch.execute',
        'ai.tool_message.execute',
        'ai.tool_document_analysis.execute',
        'ai.tool_image_analysis.execute',
        'ai.tool_web_search.execute',
        'ai.tool_agent_list.execute',
        'ai.tool_ticket_update.execute',
        'ai.tool_edit_file.execute',
        'ai.tool_write_js.execute',
        'ai.tool_workspace.view',
        'ai.tool_workspace.manage',
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
        'admin.ai_lara.manage',
        // Grants access to Lara task-model selection and recommendations.
        'admin.ai_task_model.manage',
        // Grants access to provider catalog, credentials, and model sync flows.
        'admin.ai_provider.manage',
        // Grants access to AI pricing override management.
        'admin.ai_pricing_override.manage',
        // Grants access to AI tool catalog and per-tool workspaces.
        'admin.ai_tool.manage',
        // Grants access to the operator-facing AI diagnostics surfaces:
        // the control plane itself plus standalone run drill-down pages.
        'admin.ai_control_plane.view',
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
                'admin.ai_lara.manage',
                'admin.ai_task_model.manage',
                'admin.ai_provider.manage',
                'admin.ai_pricing_override.manage',
                'admin.ai_tool.manage',
                'admin.ai_control_plane.view',
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
