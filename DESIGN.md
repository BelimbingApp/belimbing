---
version: alpha
name: Belimbing
description: Professional, compact, warm workflow UI for long operational sessions.
---

# DESIGN.md

## Overview

BLB should feel professional, compact, warm, and trustworthy. It is workflow software for long sessions: dense enough for operations, calm enough for judgment, and polished enough that users trust it. Success means users finish real work faster and leave — not more time in the app. The interface is the brand: deliberate product software with intentional taste, not a marketing site, consumer novelty, or generic enterprise gray.

Less is more, but better — every surface, label, and control must earn its place.

## Semantic color roles

Semantic roles only in Blade; warm operational base, accent for primary action, status for real feedback. Values: `resources/core/css/tokens.css`.

## Compact typography

Instrument Sans only; compact, competent type; tabular numerals where scanning matters.

## Compact layout

Compact, high-signal layouts; responsive on narrow screens. Compact does not mean cramped.

## Subtle depth

Subtle contrast, borders, and shadows; motion clarifies state at 60fps.

## Coherent shapes

Coherent rounded language; no mixed corner styles in one view.

## Reuse components

Reuse `x-ui.*` and `<x-icon>` before inventing new markup.

## Gestalt grouping

- **proximity** — related controls and labels stay close
- **similarity** — same role shares look and behavior
- **common region** — related work lives inside one surface
- **visual hierarchy** — the primary path reads first at a glance

## Scan before reading

Users scan before they read. Favor bullets, short paragraphs, and meaningful icons so key facts and actions surface at a glance — dense, not verbose.

## Stay consistent

Same thing, same look, same place — reuse established patterns, placement, and labels across modules; variation needs a user-visible reason.

## Norman feedback

Users always know what's happening and what happened. Show work in flight — loading, waiting, blocked; give every action visible, timely response; outcomes stay honest and transparent; never fail silently.

## Reduce anxiety

Calm software reduces anxiety; it does not manufacture urgency or FOMO. No nagging, badge spam, false scarcity, or engagement dark patterns — trust comes from steady, honest state, not stimulation.

## Write for humans

Make it human: plain, respectful operational language. Write for the person doing the work, not for enterprise theater or system internals.
