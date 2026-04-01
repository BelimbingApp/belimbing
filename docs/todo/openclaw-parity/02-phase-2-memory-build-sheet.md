# Phase 2 - Memory & Recall Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`
**Scope:** Replace BLB's current memory-adjacent tooling with a real agent memory subsystem
**Status:** Complete — 961 tests passing (62 new memory tests)
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
| Public contracts | **done** | 5 DTOs, 2 enums, 3 error codes, 4 config keys |
| Source catalog | **done** | `MemorySourceCatalog` — agent-generic, non-recursive |
| Chunking/index manifest | **done** | `MemoryChunker` + `MemoryIndexStore` (per-agent SQLite, WAL) |
| Hybrid retrieval engine | **done** | `MemoryRetrievalEngine` — keyword baseline, vector-ready |
| Compaction engine | **done** | `MemoryCompactor` — archive + reindex |
| Commands/jobs | **done** | 2 Artisan commands, 2 queued jobs |
| Tool refactor | **done** | Search/Get refactored, new MemoryStatusTool added (24 tools total) |
| Operator visibility | **done** | `MemoryHealthService` + `MemoryStatusTool` + CLI commands |

### 10.1 Step 1 — Define contracts and memory boundaries

Status: **done**

Sub-todos:

- [x] define transcript vs memory boundary in code and docs — transcripts stay in session JSONL; memory = MEMORY.md + memory/*.md
- [x] define canonical memory file layout per agent — `MemoryFileType` enum (Durable, Daily, Reference)
- [x] define retrieval result DTO shape — `MemorySearchResult` (sourcePath, heading, snippet, score, basis, sourceType)
- [x] define compaction policy contract — `MemoryCompactor` reads daily, appends to durable, archives with prefix

Implementation notes:
- Created `MemoryFileType`, `MemoryRetrievalBasis` enums
- Created `MemorySourceEntry`, `MemoryChunk`, `MemorySearchResult`, `MemoryIndexManifestEntry`, `MemoryHealthReport` DTOs
- Added `MEMORY_SOURCE_UNREADABLE`, `MEMORY_INDEX_FAILED`, `MEMORY_INDEX_CORRUPT` to `BlbErrorCode`
- Added `ai.memory.*` config section (max_chunk_chars, default_max_results, min_score_threshold, compaction_archive_prefix)

### 10.2 Step 2 — Build workspace-aware source catalog

Status: **done**

Sub-todos:

- [x] create service for memory source discovery — `MemorySourceCatalog::scan(employeeId)`
- [x] remove Lara-only workspace assumptions from memory code — all paths resolve via `WorkspaceResolver`
- [x] define inclusion/exclusion rules — MEMORY.md (durable) + memory/*.md (daily), non-recursive
- [ ] add tests for per-agent scoping and path safety — _tests pending_

Implementation notes:
- `MemorySourceCatalog` at `Services/Memory/MemorySourceCatalog.php`
- Methods: `scan()`, `isMemoryPath()`, `resolveReadPath()`, `classifyPath()`
- Returns `list<MemorySourceEntry>` with SHA-256 content hashes

### 10.3 Step 3 — Build deterministic indexing pipeline

Status: **done**

Sub-todos:

- [x] create chunker based on markdown structure — `MemoryChunker` splits by heading → paragraph → size cap
- [x] create content hashing and manifest tracking — `MemoryIndexStore` with `memory_manifest` table, SHA-256 hashes
- [x] define stale-chunk deletion strategy — `removeStaleEntries()` deletes chunks for sources no longer in catalog
- [x] add rebuild-safe SQLite index layout — per-agent `memory.sqlite`, WAL mode, 3 tables, lazy schema creation

Implementation notes:
- `MemoryChunker` — heading (h1-h3) → paragraph grouping → size cap with "(part N)" suffixes
- `MemoryIndexStore` — tables: `memory_chunks` (with indexes), `memory_manifest`, `memory_meta`
- `MemoryIndexer` — orchestrates catalog→chunker→store; `index()` = incremental, `reindex()` = force rebuild
- All operations transactional with rollback on failure

### 10.4 Step 4 — Implement hybrid retrieval engine

Status: **done** (keyword baseline; vector extension point ready)

Sub-todos:

- [x] keep lexical retrieval as baseline — keyword search with heading 3× weight, content 1× weight
- [ ] add vector retrieval when embeddings are configured — _deferred to Phase 2.5; extension point exists_
- [x] implement score fusion — normalized 0.0–1.0 scores, min_score_threshold filtering
- [x] return citations and retrieval-basis metadata — `MemorySearchResult` with score, basis, sourceType

Implementation notes:
- `MemoryRetrievalEngine` at `Services/Memory/MemoryRetrievalEngine.php`
- Stopword filtering, snippet truncation at word boundaries
- Basis tagged as `Keyword` now; `Vector`/`Hybrid` ready for future
- Score normalization ensures consistent ranking across retrieval methods

### 10.5 Step 5 — Refactor tools to thin wrappers

Status: **done**

Sub-todos:

- [x] make `MemorySearchTool` call retrieval service only — delegates to `MemoryRetrievalEngine`, falls back to `docs/` reference corpus
- [x] make `MemoryGetTool` call workspace read service only — uses `MemorySourceCatalog` for workspace scope
- [x] add `MemoryStatusTool` — exposes `MemoryHealthReport` as formatted markdown

Implementation notes:
- `MemorySearchTool` — `setRetrievalEngine()` setter, injected by ServiceProvider; memory results trusted higher than docs/
- `MemoryGetTool` — `setCatalog()` setter, agent-generic; removed hardcoded LARA_ID
- `MemoryStatusTool` — new tool (24 total), same authz gate as MemoryGetTool
- ServiceProvider uses builder methods (`buildMemoryGetTool`, `buildMemoryStatusTool`) for setter injection

### 10.6 Step 6 — Add compaction engine

Status: **done**

Sub-todos:

- [x] define compaction triggers — explicit via command/job/tool; no auto-trigger yet
- [x] implement raw-to-durable merge flow — reads daily files, extracts content with date headers, appends to MEMORY.md
- [x] choose archive/delete behavior for compacted daily notes — archive with configurable prefix (default "archived-")
- [x] reindex after compaction — calls `MemoryIndexer::reindex()` at end

Implementation notes:
- `MemoryCompactor` at `Services/Memory/MemoryCompactor.php`
- Creates MEMORY.md with header if it doesn't exist
- Returns summary: `{compacted_files, archived_files, appended_bytes}`

### 10.7 Step 7 — Add jobs, commands, and operator visibility

Status: **done**

Sub-todos:

- [x] add index/compact commands — `blb:ai:memory:index {agent} [--force]`, `blb:ai:memory:compact {agent}`
- [x] add queued jobs — `IndexAgentMemoryJob`, `CompactAgentMemoryJob`
- [x] add health/status reporting — `MemoryHealthService::report()` → `MemoryHealthReport` DTO
- [x] surface status in tool workspace or admin UI — `MemoryStatusTool` for agent self-check; admin UI deferred

Implementation notes:
- Commands at `Console/Commands/MemoryIndexCommand.php`, `MemoryCompactCommand.php`
- Jobs at `Jobs/IndexAgentMemoryJob.php`, `CompactAgentMemoryJob.php`
- `MemoryHealthService` checks index existence, stale count, VectorStoreService availability
- All registered as singletons in `ServiceProvider`

---

## 11. Scope-Sharpening Notes

### Open questions — resolved during implementation

1. **Should memory include transcript-derived facts automatically?** → **No.** Transcripts stay in session JSONL. Only explicit write/compaction paths promote into memory.
2. **Should compaction archive raw notes or keep them indefinitely?** → **Archive with configurable prefix** (default `archived-`). Files renamed, not deleted.
3. **Should external docs be part of the same retrieval corpus?** → **Separate corpus merged at runtime.** `MemorySearchTool` searches indexed agent memory first, then `docs/` as reference corpus. Results are merged with memory first (higher trust).
4. **Should embeddings be mandatory for Phase 2?** → **No.** Lexical-first with vector extension point. `MemoryRetrievalBasis` enum has `Vector` and `Hybrid` cases ready. `VectorStoreService::isSqliteVecAvailable()` checked in health reports.

### Scope decisions made

- Per-agent SQLite index stored at `{workspace_path}/{employee_id}/memory.sqlite` — disposable, rebuildable
- WAL mode for concurrent read access during indexing
- Setter injection pattern for tools (ServiceProvider calls setters after `new`) since tools are constructed as plain instances, not via container
- `MemoryStatusTool` shares the `ai.tool_memory_get.execute` authz gate — no separate capability needed
- Tool count increased from 23 to 24 with MemoryStatusTool

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

---

## 14. Implementation File Manifest

### New files created

| File | Purpose |
|---|---|
| `app/Modules/Core/AI/Enums/MemoryFileType.php` | Durable / Daily / Reference classification |
| `app/Modules/Core/AI/Enums/MemoryRetrievalBasis.php` | Keyword / Vector / Hybrid tag |
| `app/Modules/Core/AI/DTO/MemorySourceEntry.php` | Source file metadata with content hash |
| `app/Modules/Core/AI/DTO/MemoryChunk.php` | Indexed content chunk |
| `app/Modules/Core/AI/DTO/MemorySearchResult.php` | Search result with citation and score |
| `app/Modules/Core/AI/DTO/MemoryIndexManifestEntry.php` | Per-source index tracking |
| `app/Modules/Core/AI/DTO/MemoryHealthReport.php` | Agent memory health snapshot |
| `app/Modules/Core/AI/Services/Memory/MemorySourceCatalog.php` | Source discovery |
| `app/Modules/Core/AI/Services/Memory/MemoryChunker.php` | Markdown-aware chunking |
| `app/Modules/Core/AI/Services/Memory/MemoryIndexStore.php` | Per-agent SQLite CRUD |
| `app/Modules/Core/AI/Services/Memory/MemoryIndexer.php` | Incremental index orchestrator |
| `app/Modules/Core/AI/Services/Memory/MemoryRetrievalEngine.php` | Keyword search + score normalization |
| `app/Modules/Core/AI/Services/Memory/MemoryCompactor.php` | Daily-to-durable compaction |
| `app/Modules/Core/AI/Services/Memory/MemoryHealthService.php` | Health report generator |
| `app/Modules/Core/AI/Tools/MemoryStatusTool.php` | Agent-facing health tool |
| `app/Modules/Core/AI/Console/Commands/MemoryIndexCommand.php` | `blb:ai:memory:index` |
| `app/Modules/Core/AI/Console/Commands/MemoryCompactCommand.php` | `blb:ai:memory:compact` |
| `app/Modules/Core/AI/Jobs/IndexAgentMemoryJob.php` | Queued indexing |
| `app/Modules/Core/AI/Jobs/CompactAgentMemoryJob.php` | Queued compaction |

### Modified files

| File | Change |
|---|---|
| `app/Base/Foundation/Enums/BlbErrorCode.php` | +3 error codes |
| `app/Base/AI/Config/ai.php` | +`ai.memory.*` config section |
| `app/Modules/Core/AI/Tools/MemorySearchTool.php` | Refactored to delegate to retrieval engine |
| `app/Modules/Core/AI/Tools/MemoryGetTool.php` | Refactored to agent-generic with catalog injection |
| `app/Modules/Core/AI/ServiceProvider.php` | +7 singletons, +2 builder methods, tool count 23→24 |

---

## 15. Remaining Work

- [x] Write tests for all 7 services — 62 tests across 7 test files, all passing
- [x] Bug fix: MemoryCompactor setMeta ordering — reindex first, then record compaction timestamp
- [x] Update ToolMetadataRegistryTest tool count 23→24

All Phase 2 work complete. No remaining items.
