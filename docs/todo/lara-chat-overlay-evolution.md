# Lara Chat Overlay — Evolution Plan

**Document Type:** Planning / TODO
**Status:** Draft
**Created:** 2026-03-12
**Related:** `docs/architecture/lara-system-dw.md`, `docs/Base/AI/tool-framework.md`

---

## 1. Problem Essence

The Lara chat overlay works but feels like a chat widget, not the center-stage AI experience BLB promises. It lacks rich input affordances, layout flexibility, and the polish that makes users *want* to interact with Lara. This plan evolves the overlay from a functional sidebar into a first-class AI workspace.

### Architectural Constraint: One Codebase, All DWs

The chat UI is **not** Lara-specific. Every Digital Worker — Lara, a sales assistant, a DevOps bot — uses the same chat overlay component. Lara is a *configuration* of the shared component, not a fork of it.

**What varies per DW (data/policy):**
- Identity (name, avatar, role badge) — driven by `Employee` record
- System prompt and personality — driven by workspace config / `LaraPromptFactory`
- Available tools — driven by authz capabilities on the DW's role
- Layout defaults (Lara opens globally; other DWs open from their workspace page)
- Session path (`workspace/{employee_id}/sessions/...`)

**What is shared (code):**
- Chat overlay Livewire component and Blade view
- Composer (multi-line input, attachments, model picker)
- Session sidebar (list, create, delete, rename, collapse)
- Message rendering (Markdown, streaming, action bubbles)
- Layout modes (overlay, docked, mobile full-screen)
- State persistence (`localStorage` keys namespaced per DW)
- Keyboard shortcuts

**Implementation approach:**
- Current `LaraChatOverlay` becomes a generic `DwChatOverlay` (or is parameterized to accept any `Employee` DW)
- Lara-specific logic (orchestration commands, `LaraPromptFactory`, identity component) is injected via props or service resolution based on the DW's employee ID
- The Blade view receives identity data (name, avatar, role) as props — never hardcodes "Lara"
- `localStorage` keys are namespaced: `dw-chat-{employeeId}-open`, `dw-chat-{employeeId}-mode`, etc.

---

## 2. Current State

- **Layout:** Fixed-position floating card (56rem × 80vh desktop, full-screen mobile)
- **Composer:** Single-line `<x-ui.input>` with single send button
- **Session sidebar:** Always visible (w-64), no collapse mechanism
- **Responses:** Plain text with `whitespace-pre-wrap` — no Markdown, no streaming
- **State:** Overlay does not persist across page refreshes — lost on navigate
- **Model:** Hardcoded to Lara's `ConfigResolver` primary — no user override

---

## 3. Feature Plan

### Phase 1: Composer & Input UX (Foundation)

#### 1a. Multi-line Composer

Replace the single-line `<x-ui.input>` with an auto-expanding `<textarea>`.

- **Send:** `Enter` submits; `Shift+Enter` inserts newline (Alpine `@keydown`)
- **Auto-grow:** Expand from 1 line up to ~6 lines, then scroll internally
- **Styling:** Match existing input tokens (`px-input-x`, `py-input-y`, `border-border-input`)
- **Foundation:** This is the layout base for attachments and model selector — do it first

#### 1b. Attachments

Add attachment support to the composer for all Digital Worker chat interfaces.

- **UI:** Paperclip button (📎) in the composer bar; attachment pills/thumbnails below the textarea, removable before send
- **Backend:** `Livewire\WithFileUploads` trait; temp upload → store in session workspace (`workspace/{employee_id}/sessions/{user_id}/{session_id}/attachments/`)
- **Supported types:** Images (for vision models), text files, PDFs, CSVs
- **LLM wiring:**
  - Images → base64 in `content` array (OpenAI vision format)
  - Documents → extracted text appended to user message (reuse `DocumentAnalysisTool` pipeline)
- **Validation:** File size and type validation at the Livewire level

#### 1c. State Persistence

The overlay must survive page refreshes and SPA navigations.

- **Open/closed state:** Persist to `localStorage` via Alpine.js (`x-init` reads, `x-effect` writes)
- **Layout mode:** Persist selected mode (overlay / docked) to `localStorage`
- **Sidebar collapsed state:** Persist to `localStorage`
- **On page load:** If `localStorage` says Lara was open → re-open in the same mode
- **Implementation:** The Alpine `x-data` on the layout wrapper already manages `laraChatOpen`; extend it to read/write `localStorage`

### Phase 2: Layout Modes (Desktop Experience)

#### 2a. Dockable Side Panel

Three layout modes, managed via Alpine.js state + `localStorage`:

| Mode | Behavior | Use Case |
|------|----------|----------|
| **Overlay** (current) | Floating card, bottom-right | Quick questions, casual use |
| **Docked** | Right-side `<aside>`, pushes main content left | Sustained work sessions |
| **Detached** *(future)* | Separate browser window via `window.open()` | Multi-monitor setups |

- **Toggle:** Button in Lara header bar switches between overlay ↔ docked
- **Docked mode:** Render Lara as a sibling `<aside>` to the main layout content area; resizable via CSS drag handle or Alpine drag
- **Mobile:** Always full-screen takeover (no overlay mode — screen too small)
- **Transition:** Animate mode switch with Alpine transitions

#### 2b. Collapsible Session Sidebar

The session sidebar is collapsible in **all modes**, not just docked. This is especially critical on mobile where the sidebar currently consumes the entire screen.

- **Default state:** Collapsed (chat-first experience); user toggles via a button in the header
- **Collapsed view:** Sidebar hidden entirely; header shows a hamburger/sessions icon to expand
- **Expanded view:** Sidebar slides in as an overlay (mobile) or inline panel (desktop)
- **Persist:** Collapsed/expanded state saved to `localStorage`
- **Mobile:** Sidebar opens as a slide-over panel on top of the chat, with backdrop dismiss

### Phase 3: Model Selection (Intelligence Control)

Per-session model picker available to authorized users across all Digital Worker chat interfaces.

- **UI:** Dropdown in the composer area (or Lara header bar) showing available models from `AiProviderModel`
- **Default:** DW's configured primary model from `ConfigResolver`
- **Override:** User can select a different model per session — stored in session metadata
- **Display:** Model display name + provider badge (e.g., "Claude Sonnet · Anthropic")
- **Persistence:** Selection stored in `SessionManager` session meta; switching sessions restores the chosen model
- **Runtime:** `AgenticRuntime` / `LaraChatOverlay::sendMessage()` reads session-level model override before falling back to `ConfigResolver`

**Authorization:**

- New capability: `ai.chat.model_select` — only users with this capability see the picker
- `dw_power_user` gets it by default
- Other roles see the default model label (no picker, no override)

### Phase 4: Polish & Shine

#### 4a. Markdown Rendering for Responses

Lara's responses deserve rich formatting — not plain text `whitespace-pre-wrap`.

- **Pipeline:** Server-side Markdown → HTML via `league/commonmark` (already a Laravel transitive dependency)
- **Supported syntax:** Headings, bold/italic, code blocks (with syntax highlighting via `<pre><code>`), lists, tables, inline links
- **Sanitization:** HTMLPurifier or strict allowlist — XSS protection is non-negotiable
- **Scope:** All DW chat interfaces, not just Lara

#### 4b. Streaming Responses (SSE)

Replace synchronous `sendMessage()` with real-time token streaming.

- **LLM side:** `LlmClient` already hits an OpenAI-compatible API — enable `stream: true`, read SSE chunks
- **Transport:** Dedicated SSE endpoint (or Livewire `$stream()`) that pushes tokens to the client
- **Client:** Alpine `EventSource` listener appends tokens to the response bubble in real-time
- **Tool progress:** Show inline status during tool calls ("🔧 Running `artisan migrate:status`...")
- **Fallback:** If SSE fails, fall back to current synchronous behavior (graceful degradation)
- **Impact:** This is the single highest-impact change — transforms "submit and wait" into "live collaborator"

#### 4c. Contextual Quick Actions

Floating suggestion pills that appear based on the current page context.

- **Examples:** On Providers page → "Sync models" pill; on Employees list → "Create new employee" pill
- **Powered by:** `LaraPromptFactory` context injection — the system prompt already receives page context
- **UI:** Small pill row above the composer; clicking a pill fills the composer and optionally auto-sends
- **Implementation:** A small `x-data` Alpine component that reads page meta (route name, breadcrumb) and maps to suggested prompts
- **Not hard-coded:** The mapping can be a config file or driven by Lara's own intelligence (ask the LLM to suggest actions given the current page)

#### 4d. Conversation Search

Search across all sessions — find that conversation where Lara explained the migration strategy.

- **Backend:** `SessionSearchService` — full-text search over JSONL session files
- **UI:** Search input at the top of the session sidebar; results show matching sessions with message preview + highlight
- **Navigation:** Clicking a result selects the session and scrolls to the matching message
- **Scope:** Searches only the current user's sessions (respects session isolation)

#### 4e. Keyboard Shortcuts

- `Ctrl+L` / `Cmd+L` — Toggle Lara open/close (already planned in arch spec §9.1)
- `Ctrl+Shift+L` / `Cmd+Shift+L` — Toggle docked mode
- `Escape` — Close Lara (when focused)
- `/` in empty composer — Slash command hint/autocomplete
- **Discoverability:** Small `?` button in Lara header → shortcut cheatsheet popover

---

## 4. New Authz Capabilities

All capabilities are DW-generic — they apply to any Digital Worker chat interface, not just Lara.

| Capability | Default Role | Purpose |
|------------|-------------|---------|
| `ai.chat_model.manage` | `dw_power_user` | See and use the per-session model picker |
| `ai.chat_attachments.manage` | `digital_worker_operator`, `dw_power_user` | Upload file attachments in DW chat |

After adding to `app/Base/Authz/Config/authz.php`, sync via:
```bash
php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"
```

---

## 5. Execution Order

```
Phase 0   Generalize to DwChatOverlay      ✅ DONE — Parameterized with employeeId.
Phase 1a  Multi-line composer              ✅ DONE — Auto-grow textarea, Enter/Shift+Enter.
Phase 1c  State persistence                ✅ DONE — localStorage for open/closed, mode.
Phase 2b  Collapsible session sidebar       ✅ DONE — Inline, drag-resizable, persisted width.
Phase 1b  Attachments                       ✅ DONE — WithFileUploads, paperclip UI, vision+doc wiring, authz-gated.
Phase 2a  Dockable side panel               ✅ DONE — Overlay/docked/mobile, single Livewire instance teleported.
Phase 3   Model selection + authz           ✅ DONE — Per-session picker, ai.chat_model.manage capability.
Phase 4a  Markdown rendering                ✅ DONE — ChatMarkdownRenderer, .dw-prose CSS, strict sanitization.
Phase 4e  Keyboard shortcuts                ✅ DONE — ? cheatsheet popover, platform-aware keys.
Phase 4b  Streaming responses               ✅ DONE — LlmClient::chatStream(), AgenticRuntime::runStream(), SSE endpoint, Alpine EventSource, sync fallback.
Phase 4c  Contextual quick actions          ✅ DONE — QuickActionRegistry, route-aware pills in empty state.
Phase 4d  Conversation search               ✅ DONE — MessageManager::searchSessions(), sidebar search input, live results.
```

### Dependency Graph

```
0 (Generalize) ──→ everything

1a (Multi-line) ──→ 1b (Attachments)
                ──→ 3  (Model Picker — composer layout)

1c (Persistence) ── standalone

2b (Sidebar collapse) ── standalone
2a (Dock mode) ──→ 4c (Quick Actions — layout context)

4a (Markdown) ── standalone
4b (Streaming) ── standalone (LlmClient changes)
4d (Search) ── standalone
4e (Shortcuts) ── standalone
```

---

## 6. Files Affected (Estimated)

| Area | Files | Notes |
|------|-------|-------|
| **Livewire component** | `app/Modules/Core/AI/Livewire/LaraChatOverlay.php` | Rename/refactor to `DwChatOverlay` or parameterize with `$employeeId` prop |
| **Blade view** | `resources/core/views/livewire/ai/lara-chat-overlay.blade.php` | Generalize — identity via props, no hardcoded "Lara" |
| **Identity component** | `resources/core/views/components/ai/lara-identity.blade.php` | Generalize to `dw-identity` accepting any DW employee |
| **Layout** | `resources/core/views/components/layouts/app.blade.php` | Lara instantiation passes `Employee::LARA_ID`; other DWs mount from their workspace |
| **Services** | `SessionManager`, `MessageManager`, `AgenticRuntime`, `LlmClient`, `ConfigResolver` | Already DW-generic; no Lara-specific changes needed |
| **Authz** | `app/Base/Authz/Config/authz.php` | Add `ai.chat.model_select`, `ai.chat.attachments` |
| **New** | `SessionSearchService`, Markdown rendering pipeline, SSE endpoint/controller | All DW-generic from day one |

---

## 7. Design Principles

1. **One component, all DWs:** There is one chat overlay codebase. Lara is a configuration — her identity, prompt, and tools are injected, not hardcoded. Any feature built here works for every DW.
2. **Specialize through data, not code:** DW identity (name, avatar, badge), available tools, model config, and prompt strategy are resolved at runtime from the `Employee` record and authz. No `if ($employee->isLara())` branches in shared UI code.
3. **Progressive enhancement:** Each phase delivers standalone value. No phase is blocked on another except where explicitly noted.
4. **Mobile-first sidebar:** The session sidebar must never dominate the screen. Collapsed by default; user chooses when to see it.
5. **State survives:** Open/closed, layout mode, sidebar state, model selection — all persist across refresh and SPA navigation via `localStorage`, namespaced per DW (`dw-chat-{employeeId}-*`).
6. **Graceful degradation:** Streaming falls back to sync. Attachments degrade to text extraction. Model picker hides if unauthorized. Nothing breaks if a feature is unavailable.

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-03-12 | AI + Kiat | Initial draft — 4 phases, execution order, authz, design principles |
| 0.2 | 2026-03-12 | AI + Kiat | Completed Phase 1b (attachments), Phase 4b (streaming), Phase 4e (shortcuts). 10/12 phases done. |
| 0.3 | 2026-03-12 | AI + Kiat | Completed Phase 4c (quick actions), Phase 4d (conversation search). All 12/12 phases done. |
