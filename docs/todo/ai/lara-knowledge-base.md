# Lara Knowledge Base

> Inspired by Andrej Karpathy's LLM knowledge base workflow (April 2026):
> https://x.com/karpathy/status/2039805659525644595
> raw sources → LLM-compiled wiki → agent Q&A + health checks + filing outputs back in.

## Problem Essence

Lara retrieves knowledge by keyword-searching human-written architecture docs via a hardcoded 10-entry `KnowledgeNavigator` catalog. This does not scale, drifts as the codebase grows, and returns articles written for developers rather than for an agent. Lara deserves its own compiled knowledge layer.

## Status

Proposed

## Desired Outcome

A two-tier knowledge system: BLB core knowledge lives in `docs/wiki/`, while each licensee can carry its own overlay wiki in `extensions/{company}/docs/wiki/`. Lara compiles and maintains both layers from raw sources into agent-optimized concept articles with indexes and backlinks. Lara's tool stack resolves licensee knowledge first, then falls back to BLB core. BLB also exposes a user-facing knowledge workspace with an Obsidian-like information architecture: article tree/search on the left, rendered article in the center, context/backlinks on the right. Every non-trivial Lara exploration can be filed back into the appropriate wiki layer, so knowledge accumulates rather than evaporates.

## Public Contract

- **Storage:** BLB core knowledge lives in `docs/wiki/`. Licensee knowledge lives in `extensions/{company}/docs/wiki/`. Both are Markdown, human-readable, and version-controlled.
- **Indexes:** each wiki root owns its own `INDEX.md`, auto-maintained by the compilation process. Each index lists every article with a one-line summary and concept tags.
- **Articles:** one `.md` file per concept, e.g. `docs/wiki/ui-components.md` or `extensions/acme/docs/wiki/company-workflows.md`. Written by Lara from raw sources. Humans should rarely touch them directly.
- **Layer ownership:** `docs/wiki/` owns canonical BLB concepts. `extensions/{company}/docs/wiki/` owns company-specific additions, overrides, and adaptations. Licensee articles should extend or override core concepts, not duplicate the whole core wiki.
- **Raw sources:** BLB raw sources come from the repository root (`docs/architecture/`, `docs/guides/`, `AGENTS.md`, key `app/` source files). Licensee raw sources come from the extension itself (`extensions/{company}/docs/`, extension `AGENTS.md`, extension source files).
- **Compilation:** `php artisan lara:kb:compile` — triggers a Lara agent run that reads raw sources and writes or refreshes wiki articles for either the core layer or a named licensee layer.
- **Health check:** `php artisan lara:kb:health` — audits the selected wiki layer for gaps, stale backlinks, orphaned articles, coverage holes, and core/licensee drift. Produces a Markdown report that Lara can act on.
- **Tool surface:** `GuideTool` is upgraded to resolve the active licensee wiki first, then the BLB core wiki, rather than consulting the hardcoded `KnowledgeNavigator` catalog.
- **User workspace:** authenticated BLB page under the AI/admin area that provides an Obsidian-like read-first knowledge interface over `docs/wiki/`.
- **Filing back:** Lara can write new wiki articles from exploration outputs using `EditFileTool`, filing them into the BLB core wiki or the active licensee overlay depending on scope.

**Non-goals:**
- No RAG/vector embeddings at this scale. At ~20–40 articles and ~50–100K words, loading the index plus reading targeted articles is sufficient — Karpathy's own observation at this scale.
- No separate Obsidian/external IDE workflow. The wiki lives in the repo and is accessible via standard file tools and the in-app knowledge workspace.
- No automated CI compilation. Compilation is intentionally human-triggered; the developer decides when a refresh is worth the token cost.
- Does not replace `docs/architecture/` or `docs/guides/` — those remain the human-facing docs. The wiki is the agent-facing synthesis layer on top.
- Not a pixel-perfect Obsidian clone. We copy the interaction model that matters for BLB: navigation, reading, backlinks, and quick context; we do not recreate plugin sprawl or desktop-app behaviors.
- Licensee wikis must not fork the entire BLB wiki wholesale. The overlay should stay narrow and only capture local terminology, policy, workflows, and concept overrides.

## Top-Level Components

| Component | Responsibility |
|---|---|
| BLB core wiki | Canonical framework knowledge under `docs/wiki/` |
| Licensee overlay wiki | Company-specific additions and overrides under `extensions/{company}/docs/wiki/` |
| `INDEX.md` per layer | Auto-maintained article list with summaries and tags; replaces hardcoded `KnowledgeNavigator` catalog |
| Per-concept articles | Agent-optimized markdown, written by Lara, synthesized from multiple raw sources |
| `lara:kb:compile` command | Artisan command that runs a bounded Lara compilation agent to write or refresh wiki articles |
| `lara:kb:health` command | Artisan command that runs a Lara health-check agent to audit and report on wiki quality |
| Upgraded `GuideTool` / `KnowledgeNavigator` | Layer-aware wiki navigation instead of hardcoded catalog |
| Knowledge workspace UI | Obsidian-like three-pane interface for browsing the wiki inside BLB |
| Filing convention | Documented pattern for Lara to write exploration outputs back into the wiki |

## Design Decisions

**Agent-optimized articles, not human docs mirrored.** The wiki is not a copy of `docs/`. Lara synthesizes across multiple raw sources and writes articles in the way she would want to read them — concise, cross-referenced, task-oriented. "When building a Livewire component, see → UI Components → Form Controls → what token to use for input padding." Human docs explain architectural intent; wiki articles explain usage.

**INDEX.md replaces the hardcoded `KnowledgeNavigator` catalog.** The catalog in `KnowledgeNavigator.php` is hand-maintained and already stale by the time it's committed. The INDEX.md is maintained by the compilation agent and read at runtime — no PHP deploys needed to add a new article.

**Core wiki plus licensee overlays, not one flat corpus.** BLB framework knowledge and licensee knowledge have different ownership and update cadence. `docs/wiki/` is the base layer; `extensions/{company}/docs/wiki/` is a narrow overlay. Lara resolves the active licensee first, then falls back to BLB core. This keeps framework knowledge reusable while allowing company-specific terminology, workflows, and policy to sit next to the extension that owns them.

**Overrides must be explicit.** If a licensee article changes the meaning or usage of a core concept, the article should declare that it overrides or adapts a named BLB concept and link back to it. Silent duplication is how drift starts.

**Compilation is a bounded Lara agent run, not a code pipeline.** The compile command gives Lara a structured prompt with the list of raw source files, the current wiki state, and a task: identify what's missing, what's stale, and write or refresh articles. This keeps the logic in Lara's domain (synthesis is a language task) rather than in custom PHP scaffolding.

**One article per concept, not per source file.** The value of compilation is synthesis. "Authorization" draws from `docs/architecture/authorization.md`, `app/Base/Authz/` source, and `AGENTS.md` → one article. If source files are mapped one-to-one, we've just duplicated the docs without adding signal.

**Health checks as a separate command, not part of compilation.** Compilation writes; health checks audit. Keeping them separate means health checks can run cheaply (read-only) and produce a report Lara acts on incrementally, rather than triggering a full regeneration every time a gap is found.

**Obsidian-like interface, BLB-native implementation.** Users need more than a file tree. They need a workspace that makes relationships legible: left pane for article/index navigation and search, center pane for rendered markdown, right pane for backlinks, related concepts, source files, and article metadata. This borrows the useful mental model from Obsidian without importing an external tool or cloning its entire product surface.

**Read-first before edit-first.** The first UI should optimize for finding and understanding knowledge, not editing markdown. Editing can remain file-based or Lara-mediated initially. A lightweight read-first workspace is enough to validate whether the wiki is genuinely useful before taking on a full markdown editor.

**Filing back is a convention, not a forced workflow.** When Lara produces a non-trivial exploration or answer, she can file it as a new wiki article. This is not automatic or mandatory — it should be a natural extension of good agent behavior, documented as a pattern.

**Start with ~15 seed articles; grow incrementally.** The initial compilation targets the concepts most frequently queried: UI patterns, authorization, AI tool system, module structure, database conventions, testing, and coding conventions. Karpathy's insight is that you don't need to compile everything upfront — the wiki grows as questions are asked.

**The workspace must show provenance.** When the user is browsing or when Lara answers from the wiki, the UI and tool layer should make it obvious whether the source was BLB core or a licensee overlay. Without provenance, debugging conflicting knowledge becomes guesswork.

## Initial Article Scope

These 15 concepts form the BLB core seed compilation target:

| Concept | Key raw sources |
|---|---|
| BLB Framework Overview | `docs/brief.md`, `docs/architecture/file-structure.md`, `docs/architecture/caddy-frankenphp-topology.md` |
| Module System | `docs/architecture/file-structure.md`, `AGENTS.md` |
| UI Design Tokens | `resources/core/css/tokens.css`, `resources/core/views/AGENTS.md` |
| UI Components | `resources/core/views/components/ui/*`, `resources/core/views/AGENTS.md` |
| UI Layout & Page Structure | `docs/architecture/ui-layout.md` |
| Authorization System | `docs/architecture/authorization.md`, `app/Base/Authz/AGENTS.md` |
| Database Conventions | `docs/architecture/database.md`, `app/Base/Database/AGENTS.md` |
| Lara Architecture | `docs/architecture/ai/lara.md`, `app/Modules/Core/AI/AGENTS.md` |
| AI Tool System | `app/Base/AI/Tools/`, `app/Modules/Core/AI/Tools/` |
| Agent Memory System | `app/Modules/Core/AI/Services/Memory/` |
| Livewire Conventions | `resources/core/views/AGENTS.md`, representative component files |
| Testing Patterns | `tests/AGENTS.md`, `tests/Pest.php`, `tests/TestCase.php` |
| PHP Coding Conventions | Root `AGENTS.md` PHP section |
| Foundation Internals | `app/Base/Foundation/AGENTS.md` |
| Dev Workflow & Commands | `docs/architecture/`, `scripts/AGENTS.md`, key artisan commands |

Licensee overlays should start smaller: company overview, business terminology, custom workflows, extension-specific modules, local policy overrides, and operator runbooks.

## Phases

### Phase 1 — Wiki structure and seed INDEX

**Goal:** Establish the two-tier wiki structure with a defined article format and hand-seeded indexes that immediately replace the hardcoded `KnowledgeNavigator` catalog.

The per-layer INDEX.md files are the contract between the wiki and Lara's tooling. Everything else can be empty at first — the indexes must exist and be navigable before compilation makes sense.

**Article format:**
```
<!--
concept: <Concept Name>
tags: [tag1, tag2]
sources: [path/to/source1.md, path/to/source2.php]
backlinks: [Related Concept 1, Related Concept 2]
compiled: YYYY-MM-DD
-->

# <Concept Name>

<Content written by Lara>
```

**INDEX.md format:**
```markdown
# BLB Knowledge Index
_Auto-maintained by `lara:kb:compile`. Do not edit by hand._

## Concepts
- [Concept Name](./concept-name.md) `tag1` `tag2` — One-line summary.
```

**Overlay metadata extension:**
```markdown
<!--
layer: core | licensee
licensee: <company-slug-or-null>
extends: <Core Concept Name or null>
scope: framework | company
-->
```

- [ ] Create `docs/wiki/` directory and define the extension convention `extensions/{company}/docs/wiki/`
- [ ] Define article format (comment block front matter + Markdown body) including overlay metadata — document in `docs/wiki/README.md`
- [ ] Create hand-seeded `docs/wiki/INDEX.md` covering the 15 BLB core concepts above (entries without articles yet are stubs with `[stub]` marker)
- [ ] Define how a licensee `INDEX.md` is discovered from the active extension context
- [ ] Upgrade `KnowledgeNavigator` to read layered `INDEX.md` files at runtime instead of returning a hardcoded PHP array
- [ ] Update `GuideTool` description to mention the wiki as the primary source
- [ ] Add pointer from `docs/AGENTS.md` to the wiki system explaining the core-plus-overlay model

### Phase 2 — Initial compilation (seed articles)

### Phase 2 — Knowledge workspace UI shell

**Goal:** Provide a BLB-native Obsidian-like interface so users can browse the active wiki layer as soon as the indexes exist, even before all seed articles are compiled.

The interface should follow BLB UI conventions, not generic markdown-reader defaults. The value here is not novelty; it is making knowledge inspection fast and low-friction inside the app.

**Recommended layout:**
- Left rail: concept tree, search field, recent/opened articles
- Center pane: rendered markdown article with heading anchors and source badges
- Right pane: backlinks, related concepts, raw source references, compiled date, health warnings

**Initial scope:** read-only. No inline markdown editing, no graph visualization, no local note drafts.

- [ ] Define route / page placement under the AI/admin area using the existing app shell
- [ ] Create a Livewire page for the knowledge workspace with three-pane responsive layout
- [ ] Left pane: index tree + keyword filter + active article state
- [ ] Center pane: render markdown article content from `docs/wiki/`
- [ ] Right pane: render metadata from article header comment block plus backlinks/related concepts
- [ ] Surface provenance clearly: BLB core vs active licensee overlay
- [ ] Add a scope switch or badge when multiple licensee overlays are available to the operator
- [ ] Mobile behavior: collapse side panes behind toggles/drawers instead of forcing a desktop-only layout
- [ ] Dark-mode parity and token-based styling consistent with BLB UI rules

### Phase 3 — Initial compilation (seed articles)

**Goal:** Run a Lara compilation pass against the 15 BLB core seed concepts, then define the first licensee overlay slice. This phase is human-supervised: the developer triggers compilation per article or in batches and reviews output.

This phase produces the first real content. There is no Artisan command yet — the developer works with Lara directly in chat to compile each article. The articles are committed to the repository.

- [ ] Compile articles for: BLB Framework Overview, Module System, PHP Coding Conventions, Dev Workflow
- [ ] Compile articles for: UI Design Tokens, UI Components, UI Layout & Page Structure, Livewire Conventions
- [ ] Compile articles for: Authorization System, Database Conventions
- [ ] Compile articles for: Lara Architecture, AI Tool System, Agent Memory System
- [ ] Compile articles for: Testing Patterns, Foundation Internals
- [ ] Update INDEX.md after each batch — remove `[stub]` markers as articles are written
- [ ] Define the first licensee overlay article set: company overview, vocabulary, workflows, local policy overrides
- [ ] Smoke-check: load BLB and licensee INDEX.md via `GuideTool`, verify Lara resolves overlay first and falls back to core

### Phase 4 — Compilation command

**Goal:** `php artisan lara:kb:compile [--layer=core|licensee] [--company=<slug>] [--concept=<name>] [--all]` triggers a bounded Lara agent run to write or refresh wiki articles. Removes the manual chat workflow from Phase 3.

The compile agent receives: the selected layer's INDEX.md, the raw source file list for each concept (from a config map), the current article content (if any), and a bounded task: synthesize and write. It writes the article and updates the correct INDEX.

- [ ] Design the compilation prompt structure — what context, what task, what output format
- [ ] Define source-map config: layer + concept → list of raw source paths
- [ ] Implement `lara:kb:compile` Artisan command
  - With `--layer=core`: compile BLB core articles
  - With `--layer=licensee --company=<slug>`: compile licensee overlay articles
  - With `--concept=<name>`: compile one named article
  - With `--all`: iterate through the full source map
  - Dry-run mode: `--dry-run` prints what would compile without writing
- [ ] Wire the command to a bounded Lara agent run (reuse existing agent runtime, not a custom HTTP call)
- [ ] Write tests for: argument parsing, source map loading, dry-run output

### Phase 5 — Health check command

**Goal:** `php artisan lara:kb:health` audits the selected wiki layer without compiling. Outputs a Markdown report Lara (or a developer) can act on.

Audit checks include:
- Articles referenced in INDEX.md but file missing (`[missing]`)
- Articles with a `compiled:` date older than 90 days
- Backlinks referencing concepts with no article
- Concepts in the source map with no INDEX.md entry (`[uncovered]`)
- Articles with no backlinks (island articles — likely under-connected)
- Licensee overlays that duplicate core articles without an explicit `extends:` declaration (`[drift-risk]`)

- [ ] Implement `lara:kb:health` command — outputs a health report for the selected layer
- [ ] Health report format: section per check type, file links, severity (`warn` / `error`)
- [ ] Decide health report location per layer (`docs/wiki/HEALTH.md` vs `extensions/{company}/docs/wiki/HEALTH.md`) and keep it gitignored
- [ ] Write tests for each audit check against fixture wiki data

### Phase 6 — Filing convention and accumulation loop

**Goal:** Establish the pattern where Lara's research outputs accumulate in the wiki rather than evaporating. This closes the Karpathy loop: raw → compiled → queried → filed back → richer.

- [ ] Document the filing-back pattern in `docs/wiki/README.md`: when to file, how to name the article, how to update the index
- [ ] Document how Lara decides between filing to BLB core vs a licensee overlay
- [ ] Add a prompt instruction to the Lara system prompt: when producing a detailed investigation the user may want repeated, offer to file it as a wiki article
- [ ] Confirm `EditFileTool` is already available and appropriately permissioned for `docs/wiki/` writes
- [ ] Verify that filed articles are picked up by the next health check and compilation pass

### Phase 7 — Future: richer workspace affordances (deferred)

**Goal:** Add higher-order affordances only after the read-first workspace and compilation loop have proven useful.

These are intentionally deferred because they are easy to romanticize and easy to overbuild.

- [ ] Evaluate graph view only if backlinks become dense enough to justify it
- [ ] Evaluate command palette / quick switcher for article navigation
- [ ] Evaluate split-view article comparison for architecture refactors
- [ ] Evaluate inline article editing only if Lara-mediated filing proves too slow

### Phase 8 — Future: finetuning corpus (deferred)

**Goal:** When the wiki reaches ~40+ articles and ~100K words, evaluate using it as a synthetic dataset for domain-specific finetuning or distillation — putting the knowledge into model weights rather than context windows.

_No implementation now. Revisit when the wiki is mature enough to be worth investing in._

- [ ] Evaluate wiki quality and coverage for finetuning suitability
- [ ] Design instruction-format conversion (wiki article → (question, answer) pairs)
- [ ] Identify a suitable base model and finetuning approach
