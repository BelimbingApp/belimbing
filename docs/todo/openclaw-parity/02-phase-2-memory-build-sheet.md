# Phase 2 - Memory & Recall Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`
**Scope:** Replace BLB's current memory-adjacent tooling with a real agent memory subsystem
**Status:** Planned
**Phase Owner:** Core AI / Base AI
**Last Updated:** 2026-04-01

---

## 1. Problem Essence

Phase 2 should not be implemented as "make `memory_search` smarter"; it should be implemented as a **memory subsystem** that gives every BLB agent durable, transparent, searchable recall with markdown as source of truth and an index as a rebuildable derivative.

---

## 2. Why the Current Phase 2 Description Is Too Thin

The current Phase 2 in `00-capability-gap-audit.md` is directionally right:

1. hybrid memory search
2. per-agent memory files
3. indexing lifecycle
4. compaction design and first implementation

But if implemented literally from that short list, the likely outcome would be tactical:

- a stronger `MemorySearchTool` layered on top of today's directory scan
- a few memory files added to the workspace
- indexing bolted onto the existing tool
- compaction introduced as an afterthought

That would improve behavior, but it would still leak complexity across tools and services.

BLB should instead build a **deep module** with simple public interfaces:

- write memory sources in markdown
- index memory deterministically
- retrieve memory with citations and trust boundaries
- compact memory under explicit policy
- expose memory health to operators

---

## 3. Current Code Snapshot

The current implementation is a useful starting point, but it is not yet a memory subsystem:

### What exists now

- `MemorySearchTool` scans `docs/` and a workspace path, tokenizes text, splits by `##` headings, and scores by keyword overlap: `app/Modules/Core/AI/Tools/MemorySearchTool.php`
- `MemoryGetTool` reads `docs` or `workspace` files safely, but currently points workspace reads to Lara's workspace path: `app/Modules/Core/AI/Tools/MemoryGetTool.php`
- `VectorStoreService` only reports sqlite-vec availability and a database path; it is not yet an indexing or retrieval engine: `app/Base/AI/Services/VectorStoreService.php`
- `SessionManager` already gives BLB a per-agent workspace/session layout and per-run metadata persistence: `app/Modules/Core/AI/Services/SessionManager.php`

### Structural problems in the current shape

1. **Memory is tool-shaped, not subsystem-shaped.**
2. **Workspace memory is still Lara-centric in key paths.**
3. **Indexing has no manifest, no chunk lifecycle, and no stale-entry strategy.**
4. **Retrieval and storage policy are implicit.**
5. **Compaction has no place to live yet.**

---

## 4. From-Scratch Design: What BLB Should Build Instead

### 4.1 Public interface first

Phase 2 should expose these stable operations:

1. `scanSources(agentId)` — discover memory inputs for an agent
2. `indexAgentMemory(agentId)` — build or refresh the derived index
3. `searchAgentMemory(agentId, query, options)` — retrieve ranked memory results with citations
4. `readAgentMemory(agentId, path, range)` — read canonical markdown source
5. `compactAgentMemory(agentId, policy)` — distill daily/raw memory into durable memory
6. `memoryStatus(agentId)` — report health, freshness, and index coverage

Tools should be thin wrappers around these operations, not owners of the logic.

### 4.2 Architectural decomposition

Build Phase 2 as a set of major components:

#### A. Memory Source Catalog

Responsibility:

- defines which files and directories count as memory
- separates memory sources from general workspace files
- applies inclusion/exclusion policy

Expected sources:

- `workspace/{agent_id}/MEMORY.md`
- `workspace/{agent_id}/memory/*.md`
- optional curated workspace notes
- optional selected framework/docs corpora when explicitly configured

Non-goal:

- arbitrary recursive workspace scanning as the default memory model

#### B. Memory Indexer

Responsibility:

- reads source markdown
- chunks deterministically
- computes fingerprints
- updates the derived index
- removes stale chunks

Key invariant:

- markdown is the canonical store; the index can always be rebuilt

#### C. Retrieval Engine

Responsibility:

- executes hybrid retrieval
- merges vector and lexical scores
- returns citations, score explanations, and source metadata

Key invariant:

- retrieval returns source-backed evidence, not free-floating summaries

#### D. Compaction Engine

Responsibility:

- turns volatile notes into durable memory
- maintains a clear boundary between raw daily memory and curated long-term memory

Key invariant:

- compaction writes to canonical markdown, not only to an index

#### E. Memory Operations Surface

Responsibility:

- exposes the subsystem to tools, jobs, UI, and commands
- centralizes authz and agent scoping

---

## 5. Core Design Decisions

### 5.1 Separate transcript from memory

BLB already has transcripts in session JSONL files. Keep them separate.

- **Transcript** = conversation history
- **Memory** = durable knowledge curated for future recall

Do not make search over transcripts the default substitute for memory. That would blur two different retention and trust models.

### 5.2 Make markdown the source of truth

Canonical data should remain editable and inspectable by humans:

- `MEMORY.md` for durable knowledge
- `memory/YYYY-MM-DD.md` for raw/daily memory
- optional structured note files when needed later

The index must be disposable and rebuildable.

### 5.3 Build per-agent memory, not Lara-special memory

The subsystem must be agent-generic.

If any API assumes `Employee::LARA_ID`, it is not Phase 2-complete.

Everything should resolve through agent context:

- workspace root by `employee_id`
- memory index by `employee_id`
- retrieval scope by `employee_id`

### 5.4 Do not let tools own memory policy

`MemorySearchTool` and `MemoryGetTool` should not contain the primary rules for:

- source discovery
- chunking policy
- index freshness
- compaction
- scope boundaries

Those belong in services.

### 5.5 Prefer deterministic indexing over ad hoc scans

From scratch, BLB should add an index manifest per agent, for example:

- source path
- content hash
- indexed at
- chunk count
- embedding model used
- last compaction status

That avoids repeated full-tree rescans and makes stale data visible.

### 5.6 Retrieval must explain itself

Search results should include:

- source path
- heading/title
- snippet
- score
- retrieval basis (`vector`, `keyword`, `hybrid`)

This is important for transparency and operator trust.

---

## 6. Proposed Module Shape

Recommended service set:

- `MemorySourceCatalog`
- `MemoryChunker`
- `MemoryIndexManifest`
- `MemoryIndexer`
- `MemoryRetrievalEngine`
- `MemoryCompactor`
- `MemoryWorkspace`
- `MemoryHealthService`

Recommended command/job set:

- `blb:ai:memory:index {agent}`
- `blb:ai:memory:reindex {agent}`
- `blb:ai:memory:compact {agent}`
- `IndexAgentMemoryJob`
- `CompactAgentMemoryJob`

Recommended tool evolution:

- `MemorySearchTool` becomes thin retrieval wrapper
- `MemoryGetTool` becomes thin canonical-read wrapper
- optional later `MemoryStatusTool`

---

## 7. Retrieval Strategy

### 7.1 Hybrid by default

Use:

- vector similarity for semantic recall
- lexical/BM25-style ranking for exactness
- deterministic fusion in one retrieval engine

Do not expose vector-only retrieval as the default.

### 7.2 Chunking policy

Chunk by markdown structure, not by arbitrary token windows first.

Preferred order:

1. heading sections
2. subsection-aware splits
3. paragraph grouping
4. token cap only as a final bound

This preserves human meaning and better citations.

### 7.3 Trust boundaries

Phase 2 should explicitly distinguish:

- durable curated memory
- raw recent notes
- external reference docs

Results from each class should be labeled in metadata and formatted output.

---

## 8. Compaction Strategy

### 8.1 Compaction is not optional polish

Compaction should be part of Phase 2, not Phase 2.5.

Without compaction:

- daily notes grow unbounded
- memory quality decays
- retrieval becomes noisier

### 8.2 Compaction output model

Compaction should:

1. read daily/raw memory sources
2. extract durable facts, decisions, preferences, and standing context
3. merge them into `MEMORY.md`
4. mark or archive compacted raw notes
5. trigger reindex

### 8.3 Human visibility

Compaction must remain inspectable:

- write to markdown files
- keep provenance where practical
- avoid silent hidden summarization as the only truth

---

## 9. Operator and UI Requirements

Phase 2 should add a minimal operator surface, not just backend services.

Required visibility:

1. index freshness
2. last successful index run
3. chunk count
4. embedding availability
5. compaction status
6. last compaction output summary

This can start as tool/workspace diagnostics before a dedicated memory admin UI exists.

---

## 10. Build Plan

## Phase Status

| Area | Status | Notes |
|---|---|---|
| Public contracts | planned | Needs stable service interfaces before implementation |
| Source catalog | planned | Must remove Lara-only assumptions |
| Chunking/index manifest | planned | Not present today |
| Hybrid retrieval engine | planned | Current search is keyword-only |
| Compaction engine | planned | Not present today |
| Commands/jobs | planned | Not present today |
| Tool refactor | planned | Current tools own too much logic |
| Operator visibility | planned | No memory health surface yet |

### 10.1 Step 1 — Define contracts and memory boundaries

Status: pending

Sub-todos:

- define transcript vs memory boundary in code and docs
- define canonical memory file layout per agent
- define retrieval result DTO shape
- define compaction policy contract

### 10.2 Step 2 — Build workspace-aware source catalog

Status: pending

Sub-todos:

- create service for memory source discovery
- remove Lara-only workspace assumptions from memory code
- define inclusion/exclusion rules
- add tests for per-agent scoping and path safety

### 10.3 Step 3 — Build deterministic indexing pipeline

Status: pending

Sub-todos:

- create chunker based on markdown structure
- create content hashing and manifest tracking
- define stale-chunk deletion strategy
- add rebuild-safe SQLite index layout

### 10.4 Step 4 — Implement hybrid retrieval engine

Status: pending

Sub-todos:

- keep lexical retrieval as baseline
- add vector retrieval when embeddings are configured
- implement score fusion
- return citations and retrieval-basis metadata

### 10.5 Step 5 — Refactor tools to thin wrappers

Status: pending

Sub-todos:

- make `MemorySearchTool` call retrieval service only
- make `MemoryGetTool` call workspace read service only
- add `MemoryStatusTool` if diagnostics need a dedicated surface

### 10.6 Step 6 — Add compaction engine

Status: pending

Sub-todos:

- define compaction triggers
- implement raw-to-durable merge flow
- choose archive/delete behavior for compacted daily notes
- reindex after compaction

### 10.7 Step 7 — Add jobs, commands, and operator visibility

Status: pending

Sub-todos:

- add index/compact commands
- add queued jobs
- add health/status reporting
- surface status in tool workspace or admin UI

---

## 11. Scope-Sharpening Notes

These should be updated as implementation begins.

### Open questions to resolve in code, not philosophy

1. Should memory include selected transcript-derived facts automatically, or only explicit memory files?
2. Should compaction archive raw notes or keep them indefinitely?
3. Should external docs be part of the same retrieval corpus or a separate retriever merged at runtime?
4. Should embeddings be mandatory for Phase 2 completion, or is lexical-first with optional vectors acceptable for first ship?

### Current best judgment

1. Keep transcript and memory separate; only promote transcript material into memory through explicit write/compaction paths.
2. Archive, do not delete, by default.
3. Keep external docs as a separate corpus class merged by retrieval engine.
4. Ship lexical + vector hybrid where vectors are available, but keep the subsystem functional without embeddings.

---

## 12. Exit Criteria

Phase 2 is complete when:

1. every agent has a canonical memory workspace model
2. memory retrieval is agent-scoped, not Lara-scoped
3. retrieval is hybrid and citation-backed
4. indexing is deterministic and rebuildable
5. compaction writes durable markdown memory
6. tools are thin wrappers over subsystem services
7. operators can inspect memory health and freshness

---

## 13. What to Avoid

1. Do not hide the whole design inside `MemorySearchTool`.
2. Do not use transcript search as a substitute for memory architecture.
3. Do not make vectors the source of truth.
4. Do not hard-code Lara-specific memory assumptions into shared memory code.
5. Do not add compaction last if retrieval quality depends on it.
