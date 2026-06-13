# Belimbing Privacy Policy

**Last updated:** 2026-05-19

Belimbing is an open-source, self-hosted application platform. This policy explains what information Belimbing may process when an operator connects external services such as eBay, uses Belimbing modules, or enables AI-assisted workflows.

This policy is written for the upstream Belimbing project. A business or developer running their own Belimbing installation is responsible for configuring, hosting, securing, and operating that installation, and may need its own privacy notice for its users and customers.

## What Belimbing Collects

Belimbing itself does not run a hosted SaaS service for end users. A self-hosted Belimbing installation stores data in infrastructure controlled by the person or organization operating that installation.

Depending on which modules are enabled, a Belimbing installation may store:

- User account details such as names, email addresses, roles, permissions, and authentication metadata.
- Company and operational records such as inventory items, catalog data, settings, orders, sales, tasks, documents, photos, and audit history.
- External integration settings such as API client IDs, encrypted client secrets, OAuth tokens, selected OAuth scopes, marketplace IDs, and integration status.
- External-system data imported through integrations, such as marketplace listings, seller policies, inventory locations, order details, buyer-provided order information, fulfillment status, and related identifiers.
- AI interaction data, when enabled, such as prompts, task inputs, model responses, tool actions, cost/usage metadata, and audit logs.
- Technical logs required to operate and debug the installation, including timestamps, error details, outbound integration metadata, and redacted request/response previews.

## eBay Integration Data

When an operator connects an eBay account, Belimbing requests only the seller OAuth scopes needed for the enabled eBay workflows. At the time of writing, the recommended seller scopes are:

- `https://api.ebay.com/oauth/api_scope/sell.inventory` — to read and manage inventory items and offers.
- `https://api.ebay.com/oauth/api_scope/sell.account` — to read and manage seller account setup data such as policies and inventory locations.
- `https://api.ebay.com/oauth/api_scope/sell.fulfillment` — to read orders and fulfillment information for reconciliation.

Belimbing uses eBay data to help the operator:

- Connect and test OAuth access.
- Pull seller policies and inventory locations.
- Sync listings, offers, orders, and fulfillment information.
- Reconcile marketplace listings against Belimbing inventory.
- Prepare listing publish, revise, and end workflows when those features are enabled.

Belimbing stores eBay OAuth tokens encrypted in the installation database. Belimbing does not sell eBay data, use it for advertising, or share it with unrelated third parties. Data is used to provide the integration features requested by the operator.

## AI Provider Data

If AI features are enabled, Belimbing may send selected task inputs to configured AI providers. The operator controls which providers are configured. AI task inputs may include business records, text, images, or metadata chosen by the operator or required by the workflow.

Operators should review each AI provider's own terms and privacy policy before enabling that provider. Belimbing records usage and audit metadata so operators can understand which providers were used and why.

## Storage and Security

Belimbing is designed for self-hosting. The operator controls the server, database, storage disks, backups, access policies, and network configuration.

Belimbing includes security controls intended to reduce accidental exposure:

- Capability-based authorization for user and agent actions.
- Encrypted storage for configured secrets and OAuth tokens.
- Audit and integration logs for operational visibility.
- Redaction of sensitive headers and token-like fields in stored outbound integration previews.
- Separation between general platform behavior and installation-specific configuration.

No system can guarantee perfect security. Operators should use HTTPS, keep dependencies updated, restrict administrative access, protect backups, and rotate credentials when needed.

## Data Sharing

Belimbing shares data only when the operator configures a workflow or integration that requires it. Examples include:

- Sending OAuth requests and API calls to eBay.
- Sending selected AI task inputs to configured AI providers.
- Sending email, chat, webhook, or other integration payloads through configured providers.

Each external provider processes data under its own terms and privacy policy.

## Data Retention and Deletion

A self-hosted Belimbing operator controls retention. Operators can delete records, revoke OAuth tokens, remove provider credentials, rotate secrets, and delete backups according to their own operational and legal requirements.

For eBay specifically, an operator can revoke Belimbing's access from the eBay account authorization settings and remove stored eBay credentials/tokens from Belimbing settings. Revoking access prevents future eBay API calls but does not automatically delete historical records already synced into Belimbing, such as listings or orders.

## Production Installations

If you run Belimbing for a real business, you should publish a privacy notice that reflects your actual installation, jurisdiction, data retention practices, subprocessors, and user/customer rights. This upstream policy is a starting point, not a substitute for installation-specific legal advice.

## Contact

For questions about the upstream Belimbing project, use the project's GitHub repository:

<https://github.com/belimbingapp/belimbing>
