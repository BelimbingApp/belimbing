# BLB Framework Error Handling — TODO

**Status:** Planning  
**Priority:** Strategic  
**Context:** BLB currently mixes `RuntimeException`, `LogicException`, and `AuthorizationException` across modules. A recent example is `LaraCapabilityMatcher` throwing a generic `RuntimeException` for identifier type mismatch.

## Problem
Framework-level errors are not yet expressed through a consistent BLB contract, which makes behavior, logging, and UX less predictable.

## Why this matters
- **Deep modules need clear failure contracts**: callers should know what kind of error to expect and how to handle it.
- **Generic exceptions hide intent**: `RuntimeException` does not distinguish config errors, invariant violations, or integration failures.
- **UI/API consistency**: errors should map predictably to user-facing feedback and logs.

## Existing signals in codebase
- **Good precedent:** Authz already uses structured reason codes (`AuthorizationReasonCode`) and decision DTOs.
- **Invariant guards:** Licensee/Lara delete protection currently uses `LogicException`.
- **Generic runtime errors:** present in AI prompt assembly and some database command paths.

## Proposed direction
1. **Define BLB base exception taxonomy** in a framework-owned namespace (e.g. `app/Base/Foundation/Exceptions`):
   - `BlbException` (base)
   - `BlbConfigurationException`
   - `BlbInvariantViolationException`
   - `BlbDataContractException`
   - `BlbIntegrationException`
2. **Attach machine-readable reason codes** (enum-backed) where useful, similar to Authz.
3. **Standardize rendering/reporting policy**:
   - predictable HTTP mapping and safe user messages
   - structured logs with module, reason code, and context
4. **Migrate high-value call sites first** (incremental, no big-bang rewrite):
   - `app/Modules/Core/AI/Services/LaraCapabilityMatcher.php`
   - `app/Modules/Core/AI/Services/LaraPromptFactory.php`
   - `app/Base/Database/Seeders/DevSeeder.php`
   - `app/Base/Database/Console/Commands/MigrateCommand.php`
5. **Add tests for error contracts** (type, reason code, render behavior).

## Acceptance criteria
- New framework exceptions exist with clear semantics and documentation.
- At least Core AI and Base Database critical paths stop using generic `RuntimeException` for known domain/config/invariant failures.
- Error rendering and logging behavior are deterministic and tested.

## Open questions
- Should all framework exceptions carry a required reason code, or only selected modules?
- Should user-facing messages live in exception classes or in renderer/mapping layer?
