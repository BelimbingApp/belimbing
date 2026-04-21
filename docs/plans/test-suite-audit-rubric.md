# Test Suite Audit Rubric

**Agent:** Codex
**Status:** Active reference for `test-suite-audit.md`
**Last Updated:** 2026-04-21
**Sources:** `docs/plans/test-suite-audit.md`, `tests/AGENTS.md`, `tests/Feature/AI/ProviderConnectionsTest.php`, `tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php`

## Problem Essence

Test cleanup becomes inconsistent when each file is judged by instinct. BLB needs a short rubric that lets humans and agents make the same keep, tighten, merge, or delete decision for the same reasons.

## Desired Outcome

Every audited test ends with a clear disposition and a short reason. New tests are reviewed against the same standard, especially when they claim to protect a regression.

## Design Decisions

### 1. Keep

Keep a test when it stops a specific bad code change and already exercises the behavior with acceptable signal.

BLB example: [ProviderTestServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php:39) is a keep candidate because it drives the real `LlmClient` through multiple protocol paths and checks both success and structured error behavior. A regression in protocol routing or error normalization would break it for a concrete reason.

### 2. Tighten

Tighten a test when it covers the right behavior but leaves obvious blind spots, depends on optimistic scaffolding, or only checks the happy path where the real failures happen elsewhere.

BLB example: the provider test path was initially only covered on success. Tightening it required adding explicit error-path coverage and per-protocol `provider_name` assertions in [ProviderTestServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php:100), not replacing the whole test with a different kind of coverage.

### 3. Merge

Merge tests when several files or examples protect the same contract through repeated scaffolding. The result should be fewer, deeper tests, usually with a dataset or shared assertion helper.

BLB example: the two legacy redirect checks in [ProviderConnectionsTest.php](/home/kiat/repo/laravel/blb/tests/Feature/AI/ProviderConnectionsTest.php:18) and [ProviderConnectionsTest.php](/home/kiat/repo/laravel/blb/tests/Feature/AI/ProviderConnectionsTest.php:28) are merge candidates if the contract is simply "legacy provider routes redirect to the unified providers page." They do not need separate bespoke scaffolding.

### 4. Delete

Delete a test when it mostly restates framework behavior, checks markup without a fragile contract behind it, or cannot answer what specific bad code change it would stop.

BLB example: [ProviderConnectionsTest.php](/home/kiat/repo/laravel/blb/tests/Feature/AI/ProviderConnectionsTest.php:5) is a delete or rewrite candidate if its page-render assertions do not protect anything beyond "the page loads and contains expected copy." That may be useful while shaping UI, but it is weak long-term coverage unless tied to a fragile contract.

### 5. Regression proof standard

When a test is claimed to protect a regression, prove that claim by reproducing the pre-fix bug or by applying a narrow temporary mutation to production code. Do not strengthen the test first and present the stronger test as proof that the original test had value.

## Public Contract

During the audit, each reviewed test should receive:

- one disposition: keep, tighten, merge, or delete
- one short reason tied to a specific failure mode or contract
- an explicit follow-up if the test is tightened or merged rather than handled immediately

## New Test Review Checklist

Use this checklist for newly added or heavily revised tests:

- What specific bad code change would this test stop?
- Does it exercise behavior, or mainly its own mocks and fixtures?
- Is the important failure path covered, or only the happy path?
- Can repeated setup be collapsed with a dataset or shared helper without hiding intent?
- If this is a regression test, has its value been proven by pre-fix reproduction or a narrow production mutation?
