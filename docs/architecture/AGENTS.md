# Architecture Docs Guide

Applies to `docs/architecture/`. Read with root `AGENTS.md` and `docs/AGENTS.md`.

## What Belongs Here

Architecture docs describe stable system shape:

- ownership and boundaries
- canonical topology or layering
- what varies by environment and what does not
- where exact behavior lives in the repo

Do not turn them into:

- plans or phase lists
- setup or troubleshooting guides
- large code or config dumps

Use `docs/plans/` for execution tracking and `docs/guides/` or `docs/installation/` for operator workflow.

## Structure

Open with:

- a short preamble: `Document Type`, `Scope`, `Last Updated`
- a short `Overview` section

The overview should state:

- the main architecture shape
- the default or recommended model
- any important allowed variation
- whether the same shape applies across environments

## Current Vs Target

An architecture doc may describe either:

- current implemented architecture
- intended architecture before implementation

Be explicit in the prose about which one it is. Do not imply a design is already implemented if it is still directional.

If the doc is about current behavior, verify the relevant source files first and keep the doc aligned with them.

## Writing Rules

- Prefer stable contracts over samples. Reference source files instead of pasting large examples.
- Prefer one topology across development, staging, and production unless a real divergence is intended.
- Name files by architectural topic, not workflow.
- Keep the tone declarative.
