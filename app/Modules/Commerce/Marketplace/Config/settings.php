<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'editable' => [
        'marketplace_ebay' => [
            'label' => 'eBay marketplace',
            'description' => 'OAuth app credentials, marketplace defaults, and Sell API scopes for the eBay adapter.',
            'fields' => [
                [
                    'key' => 'marketplace.ebay.environment',
                    'label' => 'Environment',
                    'type' => 'select',
                    'scope' => 'company',
                    'default' => 'sandbox',
                    'options' => ['sandbox' => 'Sandbox', 'live' => 'Live'],
                    'help' => 'Sandbox is safest until Ham has completed the developer-account onboarding spike.',
                    'rules' => ['required', 'in:sandbox,live'],
                ],
                [
                    'key' => 'marketplace.ebay.marketplace_id',
                    'label' => 'Marketplace ID',
                    'type' => 'text',
                    'scope' => 'company',
                    'default' => 'EBAY_US',
                    'placeholder' => 'EBAY_US',
                    'help' => 'Default eBay marketplace used for listing and inventory API calls.',
                    'rules' => ['required', 'string', 'max:32'],
                    'normalize' => 'uppercase',
                ],
                [
                    'key' => 'marketplace.ebay.client_id',
                    'label' => 'Client ID',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => 'eBay app client ID',
                    'help' => 'OAuth client ID from the eBay developer app.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'marketplace.ebay.client_secret',
                    'label' => 'Client secret',
                    'type' => 'password',
                    'scope' => 'company',
                    'encrypted' => true,
                    'placeholder' => 'Leave blank to keep current secret',
                    'help' => 'Stored encrypted. Leave blank when saving unrelated settings.',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
                [
                    'key' => 'marketplace.ebay.redirect_uri',
                    'label' => 'Redirect URI',
                    'type' => 'text',
                    'scope' => 'company',
                    'placeholder' => 'https://example.test/commerce/marketplace/ebay/oauth/callback',
                    'help' => 'Must match an accepted redirect URI on the eBay developer app.',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
                [
                    'key' => 'marketplace.ebay.scopes',
                    'label' => 'OAuth scopes',
                    'type' => 'textarea',
                    'scope' => 'company',
                    'default' => "https://api.ebay.com/oauth/api_scope/sell.inventory\nhttps://api.ebay.com/oauth/api_scope/sell.fulfillment",
                    'help' => 'One scope per line. Inventory handles listings/offers; Fulfillment handles order reads.',
                    'rules' => ['nullable', 'string', 'max:2000'],
                ],
            ],
        ],
    ],
];
