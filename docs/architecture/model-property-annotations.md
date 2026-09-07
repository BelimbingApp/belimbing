Document Type: architecture
Scope: Eloquent `@property` / `@property-read` blocks for Larastan
Last Updated: 2026-09-07

# Model property annotations

## Overview

PHPDoc `@property` blocks on Eloquent models exist so Larastan (and readers) see
the attribute types the database and casts actually produce after a row is
hydrated. New annotation blocks follow the migration and the model's `casts()`
honestly. They do not copy a uniform `|null` shape from an earlier model.

The same rule applies across development, staging, and production: the type is
a property of the schema and casts, not of the environment.

## Nullability

Annotate each attribute from:

1. the owning migration column nullability, and
2. any Eloquent cast that changes the PHP type (including `encrypted`,
   `array`/`json`, `datetime`/`immutable_datetime`, and integer/boolean casts).

Use `|null` only when the column is nullable, or when the attribute is
legitimately absent on a common analysed path (for example a primary key before
the first `save()`). Do not mark every attribute `|null` "to be safe" — that
erases the type information the block exists to carry and pushes consumers into
null-guards for states the database cannot produce.

Primary keys and other NOT NULL columns on a persisted row are non-null in the
annotation. Code that constructs an unsaved model and reads `$model->id` before
insert should treat that path explicitly (guard, assert, or avoid the read)
rather than weakening the type for every caller.

## Un-cast integer columns

SQLite and Postgres can disagree about the PHP type of an integer column that
has no Eloquent cast. Prefer an explicit `integer` (or `boolean`) cast when the
attribute is part of the public model surface so both drivers hydrate the same
native type. When a cast is not yet present, the `@property` type must still
match what both drivers produce after reload; do not invent `string` for a
numeric column because one driver once returned a string.

## Hydration measurements (2026-09-07)

Probe: `tests/Feature/Base/Typing/ModelPropertyHydrationProbeTest.php` creates
representative Data Share and Audit rows, reloads them, and records
`get_debug_type` for annotated columns.

| Driver | Result |
|--------|--------|
| sqlite (phpunit default) | Un-cast integer columns (`id`, offer `bytes` / `download_count`, plan action `sequence`, plan `receipt_id`) hydrate as native `int`. Encrypted secrets hydrate as decrypted `string`. |
| pgsql | Same probe runs under the platform `postgres-mirror` CI job (`DB_CONNECTION=pgsql`). The assertions are driver-agnostic: a driver disagreement fails the suite rather than living only in a comment. |

## Scope of this rule

- **New** `@property` blocks must follow this document.
- Retrofitting the existing all-nullable families (`AuditMutation`, Audit
  Action/Schedule models, Data Share plan/receipt/offer models, and siblings
  that cited them) is a separate lane. Do not expand those blocks to "fix"
  nullability inside an unrelated pay-down PR.
- Cite this document from later typing lanes; do not cite
  `AuditMutation.php` as the precedent.

## Related

- Baseline count gate: issue #651
- Early all-nullable precedents: #845, #846 (historical; not the rule)
