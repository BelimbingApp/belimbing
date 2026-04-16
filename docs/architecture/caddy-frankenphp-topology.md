# Caddy and FrankenPHP Topology

**Document Type:** Architecture Specification  
**Scope:** Native BLB runtime across development, staging, and production  
**Last Updated:** 2026-04-16

## Overview

BLB uses one core native topology across environments:

- a system Caddy daemon owns public ingress on `:80` and `:443`
- each BLB instance runs its own FrankenPHP process locally through Octane
- the BLB instance binds to a high, non-privileged loopback port
- the system Caddy routes the BLB frontend and backend hostnames to that local listener
- Vite and Reverb remain separate internal processes behind the same BLB-local routing surface

This is the default and recommended topology because it works for a single BLB instance, multiple BLB instances on one machine, and BLB deployed alongside unrelated sites. The same shape applies in development, staging, and production; the environment differences are intentionally narrow.

`direct` mode still exists as a fallback for single-instance hosts that do not want a system ingress daemon, but it is not the architectural default.

## Source Of Truth

The source of truth for the implemented native topology is the runtime and setup contract in the repository:

- the root `Caddyfile` defines BLB's internal routing behavior
- `scripts/start-app.sh` defines how a BLB instance starts and what it owns
- `scripts/stop-app.sh` defines the process boundary for BLB-owned services
- `scripts/setup.sh` and its setup steps define how native bootstrap provisions or reuses system ingress

This document describes the architecture those files implement. It is not an implementation plan and it intentionally avoids duplicating detailed shell commands or full config samples that already exist in the runtime source.

## Architectural Goal

The goal is minimum-difference deployment across environments. Development, staging, and production should all use the same layering and the same ownership model:

- system ingress stays system-managed
- each BLB instance stays app-managed
- hostnames remain stable
- only environment-specific values change

That reduces drift between "local" and "real" deployments and avoids maintaining one topology for development and a second topology for production.

## Topology

### System Ingress Layer

The system Caddy daemon is the only public ingress owner. It is responsible for:

- listening on the public ports
- terminating TLS
- routing hostnames to the correct BLB instance
- coexisting safely with other BLB instances and unrelated sites on the same machine

BLB does not treat public ingress as app-local responsibility in the default architecture.

### BLB Instance Layer

Each BLB instance owns its own:

- FrankenPHP process
- Octane worker lifecycle
- queue worker
- Reverb process
- Vite process in environments where frontend hot reload is needed

The BLB instance binds FrankenPHP to a loopback-only high port. That port is private infrastructure, not part of the public contract.

### Internal Routing Layer

Within the BLB instance, the repository `Caddyfile` remains the single source of truth for:

- PHP request handling through FrankenPHP
- static file handling
- Vite proxying
- Reverb proxying

In shared-ingress mode, that local routing surface serves plain HTTP on the internal listener because public TLS is already handled by the system Caddy layer.

### HTTP 103 Early Hints

FrankenPHP can emit HTTP `103 Early Hints`, but BLB does not enable `103` framework-wide.

Use `103` only as a narrow, case-by-case optimization for specific HTML navigations that show measured benefit. Prefer route- or page-level opt-in over global middleware or framework-default behavior.

## Environment Parity

This topology is not only for development. It is the intended native architecture across development, staging, and production.

The differences between environments should be limited to operational values such as:

- hostnames
- TLS material and trust model
- service supervision details
- capacity settings such as workers or process limits
- whether Vite runs as a live dev server or built assets are served

The topology itself should not change:

- system Caddy still owns ingress
- BLB still runs per-instance FrankenPHP locally
- hostname-based routing still selects the BLB instance

That is the minimum-diff path. Development is not a special architecture; it is the same architecture with lighter operational settings.

## Development, Staging, And Production

### Development

Development usually keeps the same shared-ingress shape, with local domains and development-oriented TLS trust. Setup may provision or reuse a local system Caddy daemon, and Vite typically runs as a live dev server for hot reload.

### Staging

Staging should remain close to production: shared ingress, per-instance FrankenPHP, stable domains, and system-managed routing. It may still use internal or non-public TLS depending on the deployment surface, but the topology should remain unchanged.

### Production

Production uses the same layering, but with production-grade operational settings. The main change is not topology but operational posture:

- production TLS material and trust
- stronger supervision and restart policies
- tighter access and change control around the system Caddy layer
- production-oriented worker sizing and observability

Production should not require inventing a second routing architecture.

## Why Shared Ingress Is The Default

Shared ingress is the default because it is the most flexible topology:

- it supports one BLB instance cleanly
- it supports multiple BLB instances on one machine
- it supports mixed environments on one host
- it supports BLB sharing a machine with other sites
- it keeps public port ownership unambiguous

`direct` mode is useful when someone intentionally wants a simpler single-instance setup, but it is a fallback convenience path rather than the framework's preferred deployment shape.

## Ownership Boundaries

The boundary between host-managed and app-managed responsibilities is strict.

System Caddy owns:

- public listeners
- host routing
- host-level TLS termination
- coexistence with other sites

A BLB instance owns:

- its local FrankenPHP listener
- its worker and auxiliary processes
- its local runtime environment
- its app-local internal routing behavior

`start-app.sh` and `stop-app.sh` should never be treated as generic host orchestration tools. Host provisioning belongs to setup and host administration, not routine app start or stop.

## Setup Expectations

Native setup must work on both:

- a fresh machine with no existing system Caddy installation
- an already-provisioned machine where Caddy and other sites may already exist

The setup behavior should therefore be idempotent and conservative:

- reuse working system Caddy installations when present
- install what is missing when shared ingress is selected and supported
- generate BLB-owned site integration without taking ownership of unrelated host config
- keep the system Caddy integration explicit and reviewable

The architecture assumes setup is the place where shared ingress is provisioned or validated. Routine app startup should not mutate host-level ingress.

## Failure Model

The main failure class in this architecture is not app boot failure but app reachability failure. A BLB instance may start successfully while the public hostname still fails because:

- the system Caddy daemon is not running
- the BLB site integration is missing
- the BLB site integration points to the wrong local port
- another process has taken the expected local listener

This architecture therefore depends on clear operator feedback from setup and startup scripts. Reachability problems should be diagnosable from the host/app boundary, not hidden inside the BLB instance.

## Relationship To Other Documentation

Use this document for the architecture and responsibility split.

Use setup and quickstart guides for operator workflow.

Use the runtime source for exact implementation behavior.
