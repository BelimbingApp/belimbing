# Active Page Context — Live View-Context Bridge for Lara

**Build Status:** ✅ Implemented (Phases 1–3, core Phase 4)

| Component | Status |
|---|---|
| `PageContext` DTO + `fromArray()` | ✅ Done |
| `ProvidesLaraPageContext` contract | ✅ Done |
| Phase 2 DTOs (PageSnapshot, FormSnapshot, TableSnapshot, ModalSnapshot, FormFieldSnapshot) | ✅ Done |
| `ProvidesLaraPageSnapshot` contract | ✅ Done |
| `#[LaraVisible]` attribute + `FieldVisibilityResolver` | ✅ Done |
| `PageContextResolver` (contract + route fallback) | ✅ Done |
| URL-based route resolution (fixes Livewire update route bug) | ✅ Done |
| `PageContextHolder` (request-scoped) | ✅ Done |
| System prompt injection (`LaraPromptFactory`) | ✅ Done |
| `ActivePageSnapshotTool` | ✅ Done |
| `ToolCategory::CONTEXT` | ✅ Done |
| Authz capability (`ai.tool_active_page_snapshot.view`) | ✅ Done |
| Consent toggle UI (three-level: off/page/full) | ✅ Done |
| Consent wiring (Alpine → Livewire → server) | ✅ Done |
| Streaming bug fix (cache-based context handoff to SSE) | ✅ Done |
| `QuickActionRegistry::forContext()` | ✅ Done |
| Page contracts: EmployeeShow (+ snapshot), EmployeeIndex, Providers, Roles Index/Show | ✅ Done |
| Unit tests (28 tests, 106 assertions) | ✅ Done |
| Remaining pages (CompanyShow, etc.) | 🔲 Future |
| Alpine `$wire.pageSnapshot()` live-state call | 🔲 Future |

**Problem:** Lara has no awareness of what the user is currently looking at in BLB. She cannot explain the active page, reference visible form fields, or diagnose UI state ("why is save disabled?"). The only page-aware feature today is `QuickActionRegistry`, which provides static quick-action prompts per route — no live UI state.

**Scope:** A first-class architecture feature that gives Lara a live, consented, structured view of the user's active BLB page. Agent-agnostic plumbing, but Lara is the first consumer.

---

## Design Principles

1. **Component-declared.** The Livewire component that owns the state declares what Lara may see via typed contracts (`ProvidesLaraPageContext`, `ProvidesLaraPageSnapshot`). No external scraper infers it.
2. **Server-built DTOs.** Context and snapshot payloads are assembled server-side via PHP methods on the owning component. Field masking is applied via reflection on `#[LaraVisible]` before serialization — the client never handles masking.
3. **Passed at execution time.** Context flows with the message payload. No cache, no TTL, no separate endpoints. Zero stale state.
4. **Minimal prompt cost.** Phase 1 metadata (route, title, resource) is injected as a ~30-token XML tag — cheaper than a tool call round-trip. The heavy Phase 2 snapshot is tool-gated.
5. **Transparent & consented.** The user sees when Lara can see the page. Page awareness is an explicit opt-in that can be paused or scoped.
6. **Explicit over generic.** Pages opt into Lara awareness by implementing contracts. Pages without contracts get only basic route metadata. This is deliberate — explicit allowlists beat inferred extraction.

---

## Phase 1 — Page Awareness (Route + Metadata from Server-Side DTOs)

Lara learns **where** the user is and what structural elements are visible — no form values or row data yet.

### 1.1 `PageContext` DTO

A typed value object representing lightweight page metadata. Owned by the AI module.

**Location:** `App\Modules\Core\AI\DTO\PageContext`

```php
class PageContext
{
    public function __construct(
        public readonly string $route,
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $module = null,
        public readonly ?string $resourceType = null,
        public readonly int|string|null $resourceId = null,
        public readonly array $tabs = [],
        public readonly ?string $activeTab = null,
        public readonly array $visibleActions = [],
        public readonly array $breadcrumbs = [],
        public readonly array $filters = [],
        public readonly ?string $searchQuery = null,
    ) {}

    /** Render as compact XML for system prompt injection (~30 tokens). */
    public function toPromptXml(): string;

    /** Serialize for tool response. */
    public function toArray(): array;
}
```

- [ ] Create `PageContext` DTO at `app/Modules/Core/AI/DTO/PageContext.php`

### 1.2 `ProvidesLaraPageContext` Contract

Livewire page components implement this to declare what Lara sees.

```php
interface ProvidesLaraPageContext
{
    /** Build the page context DTO from this component's current state. */
    public function pageContext(): PageContext;
}
```

**Example implementation:**

```php
class EmployeeShow extends Component implements ProvidesLaraPageContext
{
    public Employee $employee;

    public function pageContext(): PageContext
    {
        return new PageContext(
            route: 'admin.employees.show',
            url: route('admin.employees.show', $this->employee),
            title: $this->employee->short_name,
            module: 'Employee',
            resourceType: 'employee',
            resourceId: $this->employee->id,
            tabs: ['Profile', 'Roles', 'Activity'],
            activeTab: $this->activeTab,
            visibleActions: ['Edit', 'Reset Password', 'Archive'],
        );
    }
}
```

**Pages without the contract** get a minimal fallback built from the route alone (see §1.3).

- [ ] Create `ProvidesLaraPageContext` interface at `app/Modules/Core/AI/Contracts/ProvidesLaraPageContext.php`
- [ ] Implement on 3–5 high-value pages initially (employees show/index, AI providers, roles, companies)

### 1.3 `PageContextResolver` — Server-Side Resolution

Resolves page context for the current request. Checks the active Livewire component for the contract; falls back to route-derived metadata.

```php
class PageContextResolver
{
    public function resolve(): ?PageContext;
}
```

**Resolution order:**

1. **Contract check:** If the current page's Livewire component implements `ProvidesLaraPageContext`, call `pageContext()` → typed DTO with full detail.
2. **Route fallback:** Derive minimal context from the current route — module from route prefix (`admin.employees.*` → `Employee`), resource type/ID from route parameters. Title from `document.title` passed by client.
3. **No context:** If no route is available (e.g., headless run), return `null`.

**How does the resolver find the active page component?** The resolver doesn't need the client to identify the component. It uses the current request's route to determine which Livewire component is active (Livewire page components are route-bound). For non-full-page Livewire components embedded in Blade views, the route itself provides enough context for the fallback.

- [ ] Create `PageContextResolver` at `app/Modules/Core/AI/Services/PageContextResolver.php`
- [ ] Route-to-module mapping (static config or convention-based prefix parsing)
- [ ] Route-to-resource extraction from route parameters

### 1.4 Pass Context with Message Submission

When the user submits a message, the Chat component resolves page context server-side and passes it to the runtime.

**Flow:**

```
User types message → Alpine onSubmit()
  → calls $wire.prepareStreamingRun()  (or $wire.sendMessage())
  → Chat component calls PageContextResolver::resolve()
  → Gets PageContext DTO (or null)
  → Writes to PageContextHolder (request-scoped singleton)
  → System prompt gets ~30-token <current_page> tag
```

**`PageContextHolder`** — request-scoped singleton that holds the resolved context for the current request lifecycle. Tools and prompt factory read from it.

```php
class PageContextHolder
{
    private ?PageContext $context = null;
    private ?PageSnapshot $snapshot = null;

    public function setContext(PageContext $context): void;
    public function getContext(): ?PageContext;
    public function setSnapshot(PageSnapshot $snapshot): void;
    public function getSnapshot(): ?PageSnapshot;
}
```

**Why not a cache or separate endpoint?** A cache introduces stale state (user edits a form for 6 minutes, cached context is expired or outdated). Resolving at execution time guarantees the context matches the current request lifecycle.

- [ ] Create `PageContextHolder` (request-scoped singleton) at `app/Modules/Core/AI/Services/PageContextHolder.php`
- [ ] `Chat::sendMessage()` — call `PageContextResolver::resolve()`, write to `PageContextHolder`
- [ ] `Chat::prepareStreamingRun()` — same
- [ ] `ChatStreamController` — call `PageContextResolver::resolve()`, write to `PageContextHolder`

### 1.5 System Prompt Injection — Lightweight Metadata Only

Phase 1 metadata is injected directly into the system prompt as a compact XML tag.

**Why inject instead of tool?** A condensed `<current_page>` tag costs ~30 tokens. A tool call round-trip costs more: the LLM generates tool-call JSON (~15 tokens), waits for execution (1–3s latency), then processes the response (~50+ tokens). For basic "what page am I on?" questions, direct injection is cheaper in both tokens and latency.

**Injection point:** `LaraPromptFactory::operationalSections()` — reads from `PageContextHolder`.

```xml
<current_page route="admin.employees.show" title="Alice Tan" module="Employee"
  resource_type="employee" resource_id="42" active_tab="Roles"
  actions="Edit, Reset Password, Archive" />
```

**Rules:**
- Only injected when `PageContextHolder` has context (not null)
- If no context: omit entirely (zero cost)
- Never includes form values, table rows, or sensitive data — that's Phase 2 tool territory

- [ ] `LaraPromptFactory::operationalSections()` — add `page_context` section from `PageContextHolder`
- [ ] Format via `PageContext::toPromptXml()` (~30 tokens)
- [ ] Omit section when `PageContextHolder::getContext()` returns null

---

## Phase 2 — Structured Page Snapshot (Forms, Tables, State)

Lara can request a **richer on-demand snapshot** via a tool. The snapshot includes form values, table schema, modals, and validation errors.

### 2.1 `PageSnapshot` DTO

A typed value object for the rich snapshot. Extends `PageContext` with form, table, and modal state.

**Location:** `App\Modules\Core\AI\DTO\PageSnapshot`

```php
class PageSnapshot
{
    public function __construct(
        public readonly PageContext $pageContext,
        public readonly array $forms = [],       // list of FormSnapshot
        public readonly array $tables = [],      // list of TableSnapshot
        public readonly array $modals = [],      // list of ModalSnapshot
        public readonly ?string $focusedElement = null,
    ) {}
}
```

Supporting DTOs: `FormSnapshot`, `TableSnapshot`, `ModalSnapshot`, `FormFieldSnapshot`.

- [ ] Create `PageSnapshot` and supporting DTOs at `app/Modules/Core/AI/DTO/`

### 2.2 `ProvidesLaraPageSnapshot` Contract

Livewire components implement this to expose rich state. Extends `ProvidesLaraPageContext`.

```php
interface ProvidesLaraPageSnapshot extends ProvidesLaraPageContext
{
    /** Build the page snapshot DTO with forms, tables, modals. */
    public function pageSnapshot(): PageSnapshot;
}
```

**Example implementation:**

```php
class EmployeeEdit extends Component implements ProvidesLaraPageSnapshot
{
    public string $name = '';
    public string $email = '';

    #[LaraVisible(masked: true)]
    public string $apiKey = '';

    #[LaraVisible(false)]
    public string $password = '';

    public function pageContext(): PageContext { /* ... */ }

    public function pageSnapshot(): PageSnapshot
    {
        return new PageSnapshot(
            pageContext: $this->pageContext(),
            forms: [
                new FormSnapshot(
                    id: 'employee-edit',
                    dirty: $this->isDirty(),
                    fields: FieldVisibilityResolver::resolveFields($this),
                    errors: $this->getErrorBag()->toArray(),
                ),
            ],
        );
    }
}
```

**The component builds its own DTO.** It decides what to include. `FieldVisibilityResolver` handles the `#[LaraVisible]` attribute reflection — the component doesn't need to manually mask each field.

- [ ] Create `ProvidesLaraPageSnapshot` interface at `app/Modules/Core/AI/Contracts/ProvidesLaraPageSnapshot.php`
- [ ] Implement on 2–3 high-value pages initially (employee edit, AI provider setup)

### 2.3 `#[LaraVisible]` Attribute + `FieldVisibilityResolver`

**Attribute:** `App\Modules\Core\AI\Attributes\LaraVisible`

```php
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class LaraVisible
{
    public function __construct(
        public readonly bool $visible = true,
        public readonly bool $masked = false,
    ) {}
}
```

**`FieldVisibilityResolver`** — reflects over a Livewire component's public properties, applies `#[LaraVisible]` rules, and returns `FormFieldSnapshot[]`.

```php
class FieldVisibilityResolver
{
    /**
     * Resolve visible fields from a Livewire component's public properties.
     *
     * @return list<FormFieldSnapshot>
     */
    public static function resolveFields(Component $component): array;
}
```

**Default masking rules (no attribute):**
- Public Livewire properties are visible
- Properties named `password`, `secret`, `token`, `api_key` → masked by default
- Properties typed as `SensitiveParameterValue` → masked by default

**All masking is server-side.** The client never sees unmasked values — the DTO is built after masking.

- [ ] Create `LaraVisible` attribute at `app/Modules/Core/AI/Attributes/LaraVisible.php`
- [ ] Create `FieldVisibilityResolver` at `app/Modules/Core/AI/Services/FieldVisibilityResolver.php`
- [ ] Unit tests: verify masking rules for annotated, unannotated, and default-masked properties

### 2.4 Snapshot Resolution Flow

The snapshot is requested via a tool. The backend resolves it from the active page component.

**Flow:**

```
Lara calls active_page_snapshot tool
  → Tool calls PageContextResolver::resolveSnapshot()
  → Resolver finds the active page's Livewire component
  → If ProvidesLaraPageSnapshot: calls $component->pageSnapshot()
      → Component builds PageSnapshot DTO
      → FieldVisibilityResolver applies #[LaraVisible] masking
      → Returns typed, sanitized DTO
  → If not implemented: returns null → tool says "This page doesn't provide detailed context"
  → Tool returns DTO as JSON to LLM
```

**How does the resolver access the page component during a tool call?** The tool executes in the Chat component's request (sync) or the `ChatStreamController` request (streaming). The page component is not active in that request.

**Resolution strategy:** The Chat component / streaming controller resolves the snapshot *before* entering the runtime, using the Livewire component bound to the current route. For full-page Livewire components (most BLB admin pages), the route uniquely identifies the component class. The resolver instantiates it with route parameters to call `pageSnapshot()`.

For pages with dynamic state that can't be reconstructed from route params alone (e.g., unsaved form edits), the client assists: Alpine calls `$wire.pageSnapshot()` on the page component at message submission time (~50-100ms Livewire round-trip) and passes the serialized DTO alongside the chat message.

```js
// In agentChatComposer onSubmit():
const pageComponent = Livewire.all().find(c =>
    c.$wire.__instance?.fingerprint?.name !== 'core.a-i.chat'
);
let snapshot = null;
if (pageComponent?.$wire?.pageSnapshot) {
    snapshot = await pageComponent.$wire.pageSnapshot();
}
this.$wire.prepareStreamingRun(snapshot);
```

This keeps the DTO server-built (masking applied in `pageSnapshot()`) while getting live state including unsaved edits.

- [ ] `PageContextResolver` — add `resolveSnapshot()` method
- [ ] `Chat::prepareStreamingRun()` — accept optional `?array $snapshot`, write to `PageContextHolder`
- [ ] `ChatStreamController` — read snapshot from request body, write to `PageContextHolder`
- [ ] Alpine `onSubmit()` — call `$wire.pageSnapshot()` on page component if available, pass to chat
- [ ] Fallback: pages without the contract → snapshot tool returns "not available for this page"

### 2.5 `ActivePageSnapshotTool`

A tool for richer inspection. Gated by `ai.tool_active_page_snapshot.read`.

**Tool contract:**

```
name:        active_page_snapshot
capability:  ai.tool_active_page_snapshot.read
category:    CONTEXT
risk_class:  READ_ONLY
```

**Execution:** Reads from `PageContextHolder::getSnapshot()`. Returns the pre-built, pre-masked `PageSnapshot` DTO as JSON.

- [ ] Create `ActivePageSnapshotTool` at `app/Modules/Core/AI/Tools/ActivePageSnapshotTool.php`
- [ ] Register in `ServiceProvider::resolveToolInstances()` (`always` group)
- [ ] Add capability `ai.tool_active_page_snapshot.read` to `config/authz.php`
- [ ] Add capability to `agent_operator` role

---

## Phase 3 — Consent & Privacy Controls

### 3.1 Consent Levels

Users control how much Lara can see, via a UI toggle in the chat header.

| Level | What Lara receives |
|---|---|
| **Off** | Nothing — system prompt omits `<current_page>`, snapshot tool returns "Page awareness is disabled" |
| **Page only** (default) | Route, title, resource ID, tabs, actions, breadcrumbs, filters (Phase 1 DTO) |
| **Full snapshot** | Above + form values (masked by `#[LaraVisible]`), tables, errors, modals (Phase 2 DTO) |

**Storage:** User preference in `localStorage` (`blb-lara-page-awareness`). Passed to the backend with the message payload so the resolver knows the consent level. Not persisted in DB — ephemeral user preference.

**Enforcement:**
- **Client:** Alpine checks consent level before calling `$wire.pageSnapshot()` on the page component (performance — skip the Livewire round-trip when snapshot isn't consented)
- **Server:** `PageContextResolver` checks consent level before resolving context/snapshot. `FieldVisibilityResolver` applies `#[LaraVisible]` masking regardless (defense in depth). The server is the trust boundary.

- [ ] Add consent level toggle to chat header bar (icon: `heroicon-o-eye` / `heroicon-o-eye-slash`)
- [ ] Tooltip showing current level and what it means
- [ ] Alpine `onSubmit()` reads consent level, only calls `$wire.pageSnapshot()` when level is `full`
- [ ] `PageContextResolver` respects consent level from payload
- [ ] Chat component passes consent level to `PageContextHolder`

### 3.2 Visual Consent Indicator

When page awareness is active, show a subtle indicator so the user knows Lara can see the page.

- [ ] Small "eye" icon in the chat header, with tooltip: "Lara can see this page"
- [ ] Icon state reflects consent level (off / page / full)

### 3.3 Audit Trail

Page context reads are tracked in existing `ai_runs` metadata. No special audit table.

- [ ] `ActivePageSnapshotTool` execution is recorded in `ai_runs.tool_actions` via the standard tool-calling loop (already handled by `AgenticRuntime`)
- [ ] System prompt injection of Phase 1 context noted in run metadata (`meta.page_context_injected: true`)

---

## Phase 4 — Action-Aware Assistance

### 4.1 Contextual Responses

With page context in the system prompt and the snapshot tool available, Lara provides contextual answers:

- "You're viewing Employee #42 (Alice Tan), on the Roles tab"
- "Save is disabled because the required field 'email' is empty"
- "This table is showing 47 employees, filtered to 'Active' status, page 1 of 4"

No new tools needed — Phase 1 gives Lara basic page awareness for free, and she calls the snapshot tool when deeper inspection is needed.

### 4.2 Smart Quick Actions

Extend `QuickActionRegistry` to consume `PageContext` DTOs instead of just route names.

```php
// Current: static route-based
$quickActions = $registry->forRoute($routeName);

// Enhanced: context-aware, consuming the same DTO
$quickActions = $registry->forContext($pageContext);
```

**Examples:**
- On a form with validation errors → "Fix validation errors"
- On a table with active filters → "Clear filters" / "Export filtered results"
- On a record detail page → "Explain this record" / "Show related data"

- [ ] Extend `QuickActionRegistry` to accept `PageContext` DTO
- [ ] Add context-aware quick actions alongside route-based ones
- [ ] Fall back to route-based actions when no page context is available

---

## Design Decisions

1. **Component-declared context over generic scraping:** The Livewire component that owns the state declares what Lara may see via typed contracts. This is explicit, testable, and stable — UI markup can change freely without breaking Lara context. Generic `Livewire.all()` introspection or `data-blb-*` DOM scraping would expose all public properties indiscriminately and break on structural changes.
2. **Server-built DTOs:** `PageContext` and `PageSnapshot` are typed PHP value objects built by the component's own methods. Masking is applied via `#[LaraVisible]` reflection before serialization. The client never handles unmasked data.
3. **Passed at execution time, not cached:** A cache (even short-TTL) introduces stale state. If a user opens the chat, edits a form for 6 minutes, and asks "why is this invalid?" — cached context would be expired or stale. Resolving at message submission time guarantees freshness.
4. **Inject Phase 1 (~30 tokens), tool for Phase 2:** A compact `<current_page>` XML tag is cheaper than a tool call round-trip (~65+ tokens + 1–3s latency). The heavy snapshot (forms, tables, modals) is tool-gated because it can be hundreds of tokens and is only needed for some questions.
5. **Backend-enforced masking:** The client cannot be trusted with security masking. A frontend bug that fails to check a masking attribute leaks sensitive data to the LLM. `#[LaraVisible]` is resolved via PHP reflection — the trust boundary is airtight.
6. **Explicit opt-in per page:** Pages implement `ProvidesLaraPageContext` / `ProvidesLaraPageSnapshot` deliberately. Pages without contracts get only route-level metadata. This is an allowlist, not an inferral — safer for privacy and clearer for maintainers.
7. **One tool for rich snapshot:** Phase 1 data lives in the system prompt; Phase 2 data is accessed via `active_page_snapshot`. No separate Phase 1 tool needed — the prompt injection is cheaper.
8. **Client calls `$wire.pageSnapshot()` on the page component:** For Phase 2, the page component builds its own DTO server-side (masking applied), but the client triggers it at message submission time via a Livewire call (~50-100ms). This gets live state including unsaved form edits while keeping masking server-side.
9. **Consent is a client preference, enforced server-side:** The user controls what flows (localStorage toggle). The backend enforces it (`PageContextResolver` checks consent level; `FieldVisibilityResolver` applies masking regardless). Dual enforcement: client skips unnecessary work; server is the security boundary.
10. **Tiny DOM fallback for shell details:** Focused element and other ambient UI state (not owned by any Livewire component) can still be read from Alpine stores. This is a narrow, scoped fallback — not a generic scraping strategy.
11. **Supersedes browser automation for same-window inspection:** The browser tool (`BrowserTool`) operates in a separate session — different cookies, no unsaved changes. Active Page Context sees the actual user's tab state.
