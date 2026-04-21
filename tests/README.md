# Tests

BLB uses [Pest 4](https://pestphp.com/) on top of PHPUnit.

## Directory Layout

```
tests/
├── Feature/           # Integration tests (HTTP, Livewire, database)
├── Unit/              # Isolated unit tests
├── Support/           # Shared helpers, fixtures, builders
├── Pest.php           # Global bindings and helpers
├── TestCase.php       # Base test case
└── TestingBaselineSeeder.php
```

## Extension Tests

Layout, conventions, and how to run extension tests are documented in [extensions/README.md](../extensions/README.md#tests).

**Guides:**

- [AGENTS.md](AGENTS.md) — test seeding, shared helpers, environment notes, quality guidelines
