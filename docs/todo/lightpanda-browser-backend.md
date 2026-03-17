<!-- cspell:ignore Lightpanda -->

# TODO: Lightpanda Browser Backend

**Status**: Proposed
**Priority**: High
**Target**: Pre-production browser backend evaluation and rollout

## Overview

Evaluate and integrate Lightpanda as a browser backend for BLB's browser automation tool.

Working assumption for this plan:

- Lightpanda is close enough to production readiness for real evaluation.
- BLB is still far from production, so this is the right phase to make a strategic browser-backend decision.
- We should optimize for the best long-term browser architecture now, not preserve Chromium by inertia.

## Why This Is Worth Doing Now

BLB's browser tool is still early. The current implementation is framed around Playwright availability and Chromium-oriented assumptions, but the tool itself is still stubbed in important paths and the surrounding browser infrastructure is not yet locked.

That makes this the cheapest moment to test a browser-engine direction change.

Potential upside if Lightpanda works well for BLB:

- lower memory footprint for concurrent Agent sessions
- faster startup for short-lived browser tasks
- better fit for server-side headless automation than full Chromium
- simpler long-term scaling story for Agent browsing workloads

## Problem Statement

BLB needs to decide whether its browser tool should continue to center Chromium/Playwright assumptions, or whether Lightpanda should become a first-class backend while the platform is still pre-production and architecturally fluid.

## Current BLB State

Today, BLB's browser capability is not yet a mature production subsystem.

Current facts:

- the browser tool still returns stubbed responses for many actions
- availability checks are currently tied to Playwright CLI resolution
- browser pool/context management is still early and in-memory
- the operational contract for browser automation is not yet frozen

This means the switching cost is still manageable.

## Desired Outcome

BLB should end this work with one of these decisions:

1. Lightpanda becomes the preferred backend for BLB browser automation.
2. Lightpanda is supported as an optional backend alongside Chromium.
3. Lightpanda is rejected for now with clear reasons and a documented re-evaluation trigger.

The key requirement is that this decision must be based on BLB's actual workloads, not generic browser benchmarks.

## Evaluation Principles

### 1. Optimize for BLB, Not the Browser Market

The question is not whether Lightpanda is a perfect universal browser.

The question is whether it is the best fit for BLB's browser-tool contract:

- navigate to public web pages
- capture structured snapshots for AI consumption
- perform deterministic interactions
- fill forms and click through workflows
- isolate sessions safely across companies and Agent runs

### 2. Pre-production Is the Right Time for Strategic Risk

BLB is not in production yet. This is exactly when we should test architectural bets that would be expensive later.

If Lightpanda is promising, we should not wait until Chromium assumptions spread through the codebase.

### 3. Keep an Escape Hatch

Even if BLB chooses Lightpanda aggressively, backend abstraction should remain explicit enough that Chromium can be retained as fallback where compatibility demands it.

## Success Criteria

Lightpanda is a good fit for BLB if it can reliably support the initial browser-tool surface:

- `navigate`
- `snapshot`
- `screenshot`
- `act` for basic interactions (`click`, `type`, `fill`, `hover`, `select`)
- `wait`
- basic tab/session isolation behavior

It should also meet these non-functional goals:

- startup time materially better than Chromium in BLB-like workloads
- lower memory use under parallel Agent sessions
- stable enough for repeatable automated tests
- operational setup simple enough for BLB administrators

## Failure Criteria

Lightpanda should not become the default backend yet if any of the following hold:

- repeated compatibility failures on BLB target websites
- brittle behavior across Lightpanda version upgrades
- CDP/automation gaps that force BLB-specific hacks throughout the stack
- missing isolation or safety guarantees compared with the expected BLB browser model
- packaging, debugging, or deployment complexity worse than the performance gain justifies

## Proposed Rollout Plan

### Phase 1: Backend Contract Extraction

Before choosing an engine, extract the browser runtime contract behind the current tool.

Deliverables:

- define a browser backend interface for BLB
- separate tool actions from browser-engine implementation details
- isolate Playwright-specific assumptions behind an adapter

Target result:

BLB can support multiple backends without rewriting the tool surface.

### Phase 2: Lightpanda Prototype Backend

Implement a prototype backend using Lightpanda for the minimum useful action set.

Scope:

- navigation
- snapshot extraction
- screenshot capture
- basic interactions
- waits

Do not attempt every advanced browser feature immediately.

### Phase 3: BLB Scenario Testing

Test against realistic BLB use cases rather than generic browser demos.

Candidate scenarios:

- content extraction from documentation pages
- login-free public websites with client-side rendering
- multi-step form filling on ordinary business sites
- pages with async fetch/XHR behavior
- sites with modest anti-automation friction

Measurements:

- success rate
- latency
- memory usage
- crash frequency
- behavior repeatability across runs

### Phase 4: Decision Gate

Choose one:

- Lightpanda default, Chromium fallback
- dual-backend support with config switch
- defer adoption and keep Chromium-oriented path

The decision should be recorded with concrete pass/fail evidence.

## Architecture Notes

### Recommended Direction

The best near-term architecture is probably not “replace Chromium everywhere immediately”.

The better design is:

- BLB browser tool exposes a stable action contract
- BLB browser runtime chooses a backend via configuration
- Lightpanda is implemented as a first-class backend candidate
- Chromium remains available as a compatibility fallback until BLB has enough confidence to narrow the default

This gives BLB room to move fast without coupling the product to one engine too early.

### Why Not Hardcode Playwright Assumptions Further

Current browser infrastructure already assumes Playwright CLI presence in places. If BLB keeps deepening that assumption now, switching later will become needlessly expensive.

This TODO exists to stop that lock-in before it happens.

## Work Items

- [ ] Document the BLB browser backend contract and supported actions
- [ ] Identify all Chromium/Playwright-specific assumptions in the browser stack
- [ ] Introduce a backend abstraction under the browser tool
- [ ] Implement a Lightpanda prototype adapter
- [ ] Make browser backend selection configurable
- [ ] Define a compatibility test suite for BLB browser actions
- [ ] Benchmark Lightpanda vs Chromium on BLB-relevant workloads
- [ ] Record pass/fail findings and decide default backend policy
- [ ] Update browser setup UX to reflect the chosen backend model

## Related Files

- `app/Modules/Core/AI/Tools/BrowserTool.php`
- `app/Modules/Core/AI/Services/Browser/BrowserContextFactory.php`
- `app/Modules/Core/AI/Services/Browser/BrowserPoolManager.php`
- `app/Base/AI/Config/ai.php`
- `docs/todo/tool-workspace-ui.md`

## Notes

- This plan intentionally treats Lightpanda as a serious candidate, not a speculative curiosity.
- Because BLB is still pre-production, architectural flexibility matters more than backward compatibility.
- The goal is not to defend Chromium by default; the goal is to choose the backend that best serves BLB's long-term browser tool.