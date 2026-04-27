# Money Architecture

**Document Type:** Architecture Specification
**Purpose:** Define how BLB stores and formats money values.
**Last Updated:** 2026-04-27

## Storage Contract

Money is stored as integer minor units plus an ISO 4217 currency code. Do not store cash amounts as floats.

Column convention:

- Amount columns use an `_amount` suffix and store integer minor units, for example `unit_cost_amount`.
- The owning row stores a sibling `currency_code` column when all money columns on that row share one currency.
- Historical rows snapshot their `currency_code`; later company default changes must not rewrite old money values.

## Runtime Contract

`App\Base\Foundation\ValueObjects\Money` is the shared parser and formatter for BLB money fields.

- `fromDecimalString()` parses operator-entered decimal strings into minor units without float math.
- `format()` renders minor units with the currency code for read views.
- `formatInput()` renders minor units back to a decimal string for editable fields.

Module forms may validate their own business limits, but they should delegate parsing and formatting to this value object instead of duplicating decimal math.
