# Base Integration Module — Agent Guidelines

## Purpose

External-system transport and observability. `Base/Integration` is the gate for BLB communication with systems outside the application: HTTP APIs, OAuth endpoints, model/catalog discovery, marketplace APIs, and future inbound or non-HTTP connectors.

## Architecture

```
app/Base/Integration/
├── ServiceProvider.php
├── Config/authz.php
├── Config/menu.php
├── Database/Migrations/
├── Livewire/OutboundExchanges/
├── Models/
│   ├── OutboundExchange.php
│   └── OAuthToken.php
└── Services/
    ├── IntegrationGateway.php
    ├── IntegrationRequest.php
    ├── IntegrationResponse.php
    ├── OAuth2Client.php
    ├── OAuthTokenStore.php
    └── OutboundExchangePruner.php
```

## Key Principles

1. **Transport gate, not domain owner** — Integration records and governs external exchanges. Domain modules still own request meaning, response parsing, user-facing errors, and business retry decisions.
2. **Observable by default** — non-LLM outbound external calls should go through `IntegrationGateway` instead of direct `Http` calls.
3. **Explicit retry policy** — no blanket retries. Callers must opt in per operation when an exchange is safe to retry.
4. **Mandatory redaction** — authorization headers, cookies, API keys, OAuth tokens, client secrets, passwords, and token-like fields stay redacted in stored payloads. Authz controls access, but it does not replace redaction.
5. **Capability-gated inspection** — metadata listing (`admin.system.outbound-exchange.list`), retained payload inspection (`admin.system.outbound-exchange.payload.view`), and cleanup/delete (`admin.system.outbound-exchange.delete`) are separate authz capabilities.
6. **General ownership** — use `owner_type`/`owner_id` plus metadata instead of module-specific nullable foreign keys so Integration remains independent of adopting modules.

## Current Boundary

AI LLM calls remain on the existing AI WireLogger for now. Non-LLM AI provider communication, such as provider model discovery, belongs behind the Integration gateway.
