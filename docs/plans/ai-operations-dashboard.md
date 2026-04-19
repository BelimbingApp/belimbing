# AI Operations Dashboard

**Agent:** Amp  
**Status:** Identified  
**Last Updated:** 2025-04-19  
**Sources:** `docs/plans/ai-control-plane-debuggability.md` (sibling — micro/diagnostic view)

## Problem Essence

There is no macro view of the AI subsystem's operational state. An operator has no way to answer "how is Lara doing overall?" — run volume, failure patterns, provider reliability trends, token consumption, tool usage distribution — without manually querying the database. The control plane covers diagnostic drill-down for specific runs/turns; this surface covers the aggregate picture.

## Desired Outcome

An operator can open a single page and immediately understand the AI subsystem's overall health and behavior — run success rates, latency trends, provider reliability, token spend, and tool usage patterns — without querying the database or piecing together data from multiple surfaces. Anomalies (rising failure rate, provider degradation, runaway token consumption) are visible at a glance and link through to the control plane for diagnostic drill-down.

## Top-Level Components

1. **Activity** — run/turn/session volume over time, status breakdown (succeeded/failed/cancelled), throughput trends.

2. **Performance** — latency distributions by provider and model, token consumption (prompt vs completion), cumulative cost tracking.

3. **Reliability** — error rates by type/provider/model, fallback and retry frequency, provider uptime trends (historical, not just point-in-time snapshot).

4. **Resources** — active browser sessions and age, memory state (daily note count, last compaction), disk usage (transcripts, wire logs, artifacts), operation dispatch queue health.

5. **Tools** — usage frequency per tool, failure and denial rates, execution time distributions.

Most data is aggregation over existing tables (`ai_runs`, `chat_turns`, `ai_chat_turn_events`, `telemetry_events`) — minimal new capture needed. Lives at **AI > Operations** as a separate menu item.

## Design Decisions

### Macro-to-micro drill-down: Operations → Control Plane

The two plans form a linked pair — the operations dashboard is the macro zoom level, the control plane (`docs/plans/ai-control-plane-debuggability.md`) is the micro. Every aggregate in the dashboard that represents inspectable entities should be clickable and deep-link into the control plane with context pre-filled. Examples: clicking a failed-run count filters the control plane's recent activity to failed runs in that time window; clicking a degraded provider opens the Health tab filtered to that provider; clicking a tool's failure count opens the Turn Inspector pre-filtered to turns where that tool errored. The control plane in turn provides breadcrumb navigation back to the operations dashboard. This means the control plane's deep-link support (Phase 4 of the debuggability plan) is a dependency for the operations dashboard's drill-down links.
