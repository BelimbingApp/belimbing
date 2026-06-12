---
version: alpha
name: Belimbing
description: Professional, compact, warm workflow UI for long operational sessions.
---

# DESIGN.md

## Overview

BLB should feel professional, compact, warm, and trustworthy. It is workflow software for long sessions: dense enough for operations, calm enough for judgment, and polished enough that users trust it. The UI should be deliberate product software, not a marketing site or consumer novelty.

## Colors

Use semantic color roles, not raw palette classes, in Blade. The warm Arid palette gives pages a calm operational base; accent is reserved for primary action emphasis; status colors are for real feedback only. Runtime token authority lives in `resources/core/css/tokens.css`.

## Typography

Use Instrument Sans only. Typography should feel compact and competent: medium-weight headings, small disciplined labels, plain operational body copy, and tabular numerals where alignment improves scanning. Avoid decorative type treatments.

## Layout

Default to compact, high-signal layouts that preserve hierarchy. Group related work with clear surfaces, keep labels close to controls, avoid oversized whitespace, and make every page responsive on narrow screens. Compact does not mean cramped.

## Elevation & Depth

Depth should be subtle: surface contrast, borders, restrained shadows, clear focus rings, and modest hover states. Use motion only to clarify state, preferably opacity or transform changes that can hold 60fps.

## Shapes

Use a coherent rounded shape language: compact rounded controls, slightly larger card and overlay radii, and fully rounded badges when they behave as pills. Do not mix unrelated corner styles in one view.

## Components

Component semantics stay in the framework. Reuse `x-ui.*` primitives and `<x-icon>` before creating new markup. Concrete Blade, Livewire, Tailwind, spacing, accessibility, and component rules live in `resources/core/views/AGENTS.md`; rendered examples live in `Administration > System > UI Reference`.

## Do's and Don'ts

- Do make every surface, label, action, icon, and motion cue earn its place.
- Do keep UI beautiful, accessible, fast, responsive, and truthful.
- Do use semantic tokens and established components.
- Don't introduce one-off color values, raw repeated controls, or arbitrary styling in Blade.
- Don't overload accent or status colors until hierarchy collapses.
- Don't use cinematic motion, visual clutter, or interaction friction as decoration.
