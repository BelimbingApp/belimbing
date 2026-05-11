<?php

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
                    'help' => 'Stored encrypted. Use the eye control to verify a newly typed secret before saving.',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
                [
                    'key' => 'marketplace.ebay.redirect_uri',
                    'label' => 'Redirect URI',
                    'type' => 'readonly',
                    'value_route' => 'commerce.marketplace.ebay.oauth.callback',
                    'help' => 'Copy this BLB-generated URI into the eBay developer app accepted redirect URLs.',
                ],
                [
                    'key' => 'marketplace.ebay.scopes',
                    'label' => 'OAuth scopes',
                    'type' => 'checkbox-list',
                    'scope' => 'company',
                    'default' => [
                        'https://api.ebay.com/oauth/api_scope/sell.inventory',
                        'https://api.ebay.com/oauth/api_scope/sell.account',
                        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
                        'https://api.ebay.com/oauth/api_scope/commerce.taxonomy',
                    ],
                    'help' => 'Select the API families BLB will request during seller OAuth.',
                    'options' => [
                        'https://api.ebay.com/oauth/api_scope/sell.inventory' => [
                            'label' => 'Sell Inventory',
                            'help' => 'Read and write inventory items, offers, listing publication, and listing state.',
                        ],
                        'https://api.ebay.com/oauth/api_scope/sell.account' => [
                            'label' => 'Sell Account',
                            'help' => 'Read and manage business policies and inventory locations used by offers.',
                        ],
                        'https://api.ebay.com/oauth/api_scope/sell.fulfillment' => [
                            'label' => 'Sell Fulfillment',
                            'help' => 'Read orders so BLB can reconcile sales and mark inventory sold.',
                        ],
                        'https://api.ebay.com/oauth/api_scope/commerce.taxonomy' => [
                            'label' => 'Commerce Taxonomy',
                            'help' => 'Read category trees, category suggestions, required aspects, and fitment metadata.',
                        ],
                    ],
                    'rules' => ['required', 'array', 'min:1'],
                ],
            ],
        ],
    ],
];
