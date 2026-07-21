# Development Table Mirror Runbook

Document Type: Operational runbook
Scope: Provider onboarding and explicit complete-table data handoff between Belimbing development databases
Last Updated: 2026-07-21

Development Table Mirror moves the complete rows of explicitly selected registered tables between this local development instance and one configured provider database. It is a development-only allocation tool, not production replication. The immutable offer workflow in [`data-share.md`](data-share.md) remains the reviewed path for promotion into staging or production.

The architecture and implementation tracker live in [`../plans/base-development-table-mirror.md`](../plans/base-development-table-mirror.md).

## The two transfer modes

| Mode | Endpoints | What moves | Local tools |
|---|---|---|---|
| Portable data | SQLite ↔ PostgreSQL | Complete selected-table rows into schema already created by migrations | No `pg_dump` or `psql` |
| Native PostgreSQL | PostgreSQL ↔ PostgreSQL | Complete selected PostgreSQL table images, including supported table-owned DDL | Compatible `pg_dump` and `psql` |

Portable data is the normal SQLite-local path. It does not translate SQLite DDL into PostgreSQL. Code and migrations own schema; the mirror owns selected data. A portable operation is available only when the selected table already exists with compatible columns, unique/primary keys, and foreign keys on both endpoints.

Native PostgreSQL is selected automatically only when both endpoints are PostgreSQL and the server/client versions pass the stricter preflight. Native mode retains exact `Create`, `Replace`, and `Delete` table-image actions. Portable mode only performs `Replace rows`; a missing or incompatible table blocks and directs the operator back to migrations.

## Providers

**Supabase** is the first hosted provider adapter. **PostgreSQL** is the generic adapter for a licensee-managed server or another PostgreSQL host. Provider identity and database engine are separate: both current providers expose PostgreSQL, while local development may continue using SQLite.

A future provider belongs in the provider registry and supplies its own credential/preflight behavior. It does not require branches in the table picker or mirror policy.

## Prepare the checkout

Before connecting a provider:

1. Move relevant code and migrations through Git to every participating checkout.
2. Run the normal local migrations. Do not use mirroring as a substitute for schema deployment.
3. In **Administration → System → Data Share → Settings → Identity**, set a stable local instance ID and the role **Development**.
4. Stop or isolate workers that could act on copied runnable rows until the destination smoke check is complete.
5. Plan a separate transfer for filesystem-owned uploads or module artifacts. Database rows do not copy files.

The mirror refuses staging and production roles, identical endpoint instance IDs, protected Base/runtime tables, views and other unsupported relations, ambiguous ownership, and unselected incoming foreign-key dependencies.

## Prepare Supabase access

Belimbing can discover an existing project or create a dedicated development-only project. The recommended path is a dedicated project; never select a staging or production project as a mirror.

The one manual account step is creating a Supabase Management API access token from the [Supabase account token page](https://supabase.com/dashboard/account/tokens). Paste that token into Belimbing only for setup. Belimbing uses it to list organizations/projects and, when requested, create the dedicated project. The token is not the database credential: it leaves browser state after discovery, remains encrypted in the active session, and is deleted after setup completes or is reset.

Belimbing chooses a provider-reported port-5432 session pooler when available and otherwise builds the direct database endpoint. Both support transactions, migrations, catalog inspection, and advisory locks. The advanced recovery field also accepts either mode, but not a transaction-pooler URL. SSL is always required outside the automated test environment.

The database role needs permission to:

- create and migrate the Belimbing schema during initialization;
- inspect PostgreSQL catalogs;
- acquire/release advisory locks;
- read and replace rows in selected tables; and
- manage selected table images when native PostgreSQL mode is used.

Keep this authority limited to the dedicated development database. The mirror uses the PostgreSQL connection, not the Supabase Data API. Review and revoke `anon`/`authenticated` access if development data must not be exposed through project APIs.

## Connect and initialize through Settings

Open **Administration → System → Data Share → Settings → Development mirror**.

For **Supabase**:

1. Choose **Get Supabase access token**, create a token in the Supabase account page, and paste it into Belimbing.
2. Choose **Find my projects**. Belimbing validates the token and discovers available organizations/projects.
3. Use the recommended **Create a dedicated project** path, choose the organization and data region, then confirm creation. Belimbing generates the database password and connection details. Creating a project can affect Supabase billing.
4. To reuse an existing development project instead, select it and enter its database password. Supabase does not reveal an existing database password through its Management API.
5. Let Belimbing test the generated connection and initialize the schema. If Supabase is still provisioning a new project, wait for the ready state and choose **Finish setup**; do not create a second project.

For **PostgreSQL**, enter the server's connection URL, test it, save settings, then initialize the schema. An advanced manual URL remains available for Supabase recovery, but it is not the normal setup path.

Testing distinguishes reachable from ready. A fresh provider database reports a valid connection and asks for initialization instead of presenting a dead end.

For Supabase, Belimbing first uses a provider-returned port-5432 session pooler when available so IPv4-only workstations do not need to understand pooler coordinates. It otherwise uses the project's direct database endpoint. Port-6543 transaction poolers are not accepted for migrations and mirror transactions.

Initialization runs the checkout's application migrations against the provider connection, reconciles migration-owned table registration, creates a new distinct remote instance ID, and sets the remote role to **Development**. It does not copy the local instance ID, `base_settings`, the mirror credential, sessions, queues, caches, migration history as user data, or any domain rows.

Initialization is migration-safe: existing application tables are not deliberately overwritten. If the provider already carries incompatible or non-development Belimbing state, initialization and mirroring fail closed; inspect that database rather than forcing it.

After initialization, continue in **Data Share → Mirror**. The first data load uses the same explicit table review as every later push—there is no hidden “copy everything” step.

## Credential handling

The provider URL is stored as encrypted global setting `data_share.mirror.url`; the provider key is stored separately as `data_share.mirror.provider`. Non-secret Supabase project identity is stored separately so Settings can name and link to the selected project without decrypting the URL. The plaintext URL is write-only after save and is never placed in persistent settings caches, diagnostics, process arguments, or operator-visible status.

Leaving the masked credential unchanged preserves it. Entering an advanced replacement tests the new URL before saving. **Remove connection** deletes only the local encrypted setting and Supabase project metadata; it does not delete the provider project or revoke its database role.

Encrypted settings are bound to this runtime's `APP_KEY`. Configure the provider separately on a development machine with a different key; do not copy ciphertext.

## Initial and routine data workflow

1. Open **Administration → System → Data Share → Mirror**.
2. Confirm the displayed provider and transfer mode.
3. Filter by module or search if useful. Filters change visibility only.
4. Select every exact table intended for this operation. The list must be non-empty.
5. Choose **Push N selected tables to {provider}** or **Pull N selected tables from {provider}**.
6. Review every action and blocker. Review is read-only.
7. Confirm the separately reviewed operation.
8. Verify reported counts, an unselected control table, relationships, and destination application behavior.

Push makes Local authoritative for the selected tables. Pull makes the provider authoritative. There is no row merge, implicit dependency expansion, or `DROP CASCADE`.

In portable mode, the engine takes one consistent source snapshot, stores transient transfer material in a private `0600` file, deletes destination rows child-first, inserts source rows parent-first in bounded chunks, resets the destination identity/sequence state, verifies selected row counts, and commits all selected destination changes in one transaction. The temporary snapshot is deleted on every reported outcome.

## Portable blockers

| Blocker | Response |
|---|---|
| Selected table missing on either endpoint | Move code and apply the matching migrations; do not ask the mirror to invent DDL. |
| Columns, primary/unique keys, or foreign keys differ | Align the migration-owned schema before retrying. |
| Selected tables contain a foreign-key cycle | Split/rework the data or schema; portable insertion requires an acyclic selection. Native PostgreSQL mode can retain a fully selected table-image cycle. |
| Unselected target table references a selected table | Select that dependent deliberately if it belongs in the handoff, or resolve through its owning migration/data workflow. |
| Selected child requires a missing non-selected parent | Migrate and seed/transfer the parent prerequisite first. |
| Registry ownership differs or migration provenance is absent | Reconcile module ownership and checkout code before moving data. |
| Relation is protected, a view, partition, or other unsupported kind | Remove it; move its definition/state through the owning framework workflow. |

Protected selections include credentials/settings, Data Share ledger and package state, database registries, migrations, cache, sessions, queues, failed jobs, and job batches.

## CLI

Review without mutation:

```text
php artisan blb:db:mirror-tables --direction=push --table=module_items --table=module_item_lines
```

After checking the exact review, execute:

```text
php artisan blb:db:mirror-tables --direction=push --table=module_items --table=module_item_lines --execute
```

Use `--direction=pull` for provider → Local and `--json` for machine-readable status. CLI and web use the same provider, mode, lock, selection, review, and safety policy.

## Concurrency and failure truth

The provider endpoint is the coordination authority for both directions. One bounded provider advisory lock is acquired before the final review and source snapshot and held through destination commit. Push and pull therefore serialize against the same lock; a waiting operation cannot apply a snapshot captured before it acquired authority.

- A stale state token says **review again** and makes no change.
- A blocked preflight or unavailable lock makes no change.
- A server-reported write failure rolls back the selected destination transaction.
- Only a lost client result during/after commit is reported as potentially indeterminate.

After an indeterminate result, refresh the catalog and inspect every selected table before retrying. Never infer rollback from a missing client acknowledgement.

Unselected tables remain outside the transaction. There is no retained mirror backup or checkpoint; use the provider's development backup/restore facilities for unrelated recovery requirements.

## Rotation and removal

To rotate credentials:

1. create or rotate the provider database credential;
2. for Supabase, run account discovery again, choose the existing development project, and enter its new database password; for generic PostgreSQL, enter the complete new URL;
3. let Belimbing test and save the replacement encrypted connection; and
4. revoke the old provider credential when the host uses separate credentials.

To disconnect, use **Remove connection**, then revoke or delete the provider credential separately. Removal does not mutate provider tables or delete the Supabase project.

## Handoff evidence

Record only the date, provider label, source/target instance names, transfer mode, direction, exact selected tables, action counts, result, and redacted destination smoke checks. Never record database URLs, host/user/password coordinates, domain values, temporary paths, or hashes derived from private data.
