<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'editable' => [
        'ai_costs' => [
            'label' => 'AI cost controls',
            'description' => 'Provider choice and warning thresholds used by Lara tasks before richer cost telemetry lands.',
            'fields' => [
                [
                    'key' => 'ai.providers.primary_provider_key',
                    'label' => 'Primary provider key',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => 'openai',
                    'help' => 'Preferred provider for Lara tasks when task-specific routing does not override it.',
                    'rules' => ['nullable', 'string', 'max:100'],
                ],
                [
                    'key' => 'ai.providers.backup_provider_key',
                    'label' => 'Backup provider key',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => 'moonshot',
                    'help' => 'Optional failover provider key. Routing must opt into backup use explicitly.',
                    'rules' => ['nullable', 'string', 'max:100'],
                ],
                [
                    'key' => 'ai.cost.monthly_warning_amount',
                    'label' => 'Monthly warning amount',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => '25.00',
                    'help' => 'Minor billing still belongs to provider caps; BLB uses this for early operator warnings.',
                    'rules' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
                ],
            ],
        ],
        'lara_channels' => [
            'label' => 'Lara channels',
            'description' => 'Chat-channel credentials and identity bindings used by Lara phone transports.',
            'fields' => [
                [
                    'key' => 'lara.channels.telegram.bot_token',
                    'label' => 'Telegram bot token',
                    'type' => 'password',
                    'scope' => 'company',
                    'encrypted' => true,
                    'placeholder' => 'Leave blank to keep current token',
                    'help' => 'Stored encrypted. Used by the Telegram Lara channel adapter.',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
                [
                    'key' => 'lara.channels.telegram.authorized_chat_id',
                    'label' => 'Telegram authorized chat ID',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => '123456789',
                    'help' => 'Chat ID allowed to act as the configured operator in this BLB install.',
                    'rules' => ['nullable', 'string', 'max:100'],
                ],
            ],
        ],
    ],
];
