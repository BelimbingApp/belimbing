# Lara — BLB System Digital Worker

**Document Type:** Architecture Specification
**Status:** Draft
**Last Updated:** 2026-03-05
**Related:** `docs/architecture/ai-digital-worker.md`, `docs/architecture/user-employee-company.md`

---

## 1. Problem Essence

BLB is an AI-native framework, but AI activation is currently optional and user-initiated. There is no built-in AI presence that guides users through setup, configuration, and daily operations. Lara fills this gap as a **framework-level system Digital Worker** — always present, provisioned at install, and the default AI touchpoint for every user.

---

## 2. Decision Summary

1. Lara is a **fixed, system-level Digital Worker** — not a user-provisioned DW.
2. Her Employee record is seeded during installation, mirroring the Licensee pattern.
3. She is **delete-protected** and **non-archivable** (same boot-level guard as the Licensee company).
4. She belongs to the Licensee company (`company_id = Company::LICENSEE_ID`).
5. She reports to the **first user** (system admin) — no supervisor required for her to exist.
6. "Activation" means connecting Lara to a working AI provider — the record exists from day one, but she needs a configured provider to function.
7. When Lara is not activated, the status bar shows a persistent warning (same pattern as "Licensee not set").
8. Lara is accessible to **every authenticated user**, not scoped to a single supervisor's playground.

---

## 3. Architectural Parallels

| Aspect | Licensee | Lara |
|---|---|---|
| Identity | `Company::LICENSEE_ID = 1` | `Employee::LARA_ID` (well-known constant) |
| Seeded at | Installation / `migrate --dev` | Installation / `migrate --dev` |
| Delete protection | `LogicException` in `Company::boot()` | `LogicException` in `Employee::boot()` |
| Status bar alert | "Licensee not set" → links to setup | "Lara not activated" → links to setup |
| Setup page | `admin/setup/licensee` | `admin/setup/lara` |
| Belongs to | — | Licensee company (always) |
| Cannot be | Deleted | Deleted, archived, reassigned to another company |

---

## 4. Identity Model

### 4.1 Employee Record

Lara is an Employee row with `employee_type = 'digital_worker'` and a well-known identifier.

**Constant:**

```php
// Employee.php
public const LARA_ID = 1;
```

**Key attributes:**

| Field | Value |
|---|---|
| `id` | `Employee::LARA_ID` (1) |
| `company_id` | `Company::LICENSEE_ID` (1) |
| `employee_type` | `digital_worker` |
| `employee_number` | `SYS-001` |
| `full_name` | `Lara Belimbing` |
| `short_name` | `Lara` |
| `designation` | `System Assistant` |
| `job_description` | BLB's built-in AI assistant. Guides users through setup, configuration, and daily operations. |
| `status` | `active` |
| `supervisor_id` | First admin employee (set during setup) |

### 4.2 Identification — Well-Known ID (No `is_system` Column)

Lara is identified by `Employee::LARA_ID = 1`, mirroring the Licensee pattern (`Company::LICENSEE_ID = 1`). No additional column is needed.

**Why not an `is_system` flag?**
- The Licensee pattern proves a well-known ID is sufficient — `Company` has no `is_system` column.
- Only one system DW exists (Lara). A boolean column where exactly one row is `true` is schema noise.
- All guards and queries use the constant directly: `$employee->isLara()`, `Employee::query()->find(Employee::LARA_ID)`.

**Model helpers:**

```php
// Employee.php
public const LARA_ID = 1;

public function isLara(): bool
{
    return $this->id === self::LARA_ID;
}
```

**ID collision concern:** Same as Licensee. Lara is created during install before any user-created employees. `MigrateCommand` resets the PostgreSQL sequence afterward (existing pattern from `ensureLicenseeCompanyExists()`).

### 4.3 Delete Protection

```php
// In Employee::boot()
static::deleting(function (Employee $employee): void {
    if ($employee->isLara()) {
        throw new \LogicException('Lara cannot be deleted.');
    }
});
```

---

## 5. Provisioning

### 5.1 When Lara Is Created

Lara's Employee record is created during the same flow that creates the Licensee:

| Path | Trigger |
|---|---|
| `scripts/setup-steps/60-migrations.sh` | Fresh install — after Licensee creation, create Lara |
| `MigrateCommand::ensureLicenseeCompanyExists()` | `php artisan migrate --dev` — after Licensee, ensure Lara exists |
| `admin/setup/lara` | Manual activation via UI (if missed during install) |

**Ordering constraint:** Licensee must exist before Lara (she belongs to the Licensee company).

### 5.2 Provisioning vs Activation

| State | Record exists? | Provider configured? | Status bar | Functional? |
|---|---|---|---|---|
| **Not provisioned** | No | No | "Lara not set up" (danger) | No |
| **Provisioned, not activated** | Yes | No | "Lara not activated" (warning) | No |
| **Activated** | Yes | Yes | (clean) | Yes |

"Activation" is not a flag on the Employee record. It is the **runtime check** that Lara's LLM configuration resolves to a working provider. The `ConfigResolver` already handles this — activation = `ConfigResolver::resolve(Employee::LARA_ID)` returns a non-empty config, or `ConfigResolver::resolveDefault(Company::LICENSEE_ID)` succeeds.

---

## 6. Setup Page (`admin/setup/lara`)

A Volt component similar to the Licensee setup page. Two scenarios:

### 6.1 Lara Record Missing (Fresh Install Without Script)

Shows a single-action page:
- Explains what Lara is and why she's required.
- "Activate Lara" button creates the Employee record and redirects to LLM configuration.

### 6.2 Lara Record Exists But No Provider

Shows the LLM configuration step:
- Select from available company providers (reuse provider selector from DW onboarding §16 of `ai-digital-worker.md`).
- Pick a model.
- "Activate" writes `config.json` to Lara's workspace.

If no providers exist at all, the page links to `admin/ai/providers` to configure one first.

---

## 7. Status Bar Integration

Extend the existing status bar with a Lara activation check:

```blade
@if (!$laraActivated)
    <a href="{{ route('admin.setup.lara') }}" wire:navigate
       class="text-status-warning hover:underline flex items-center gap-1">
        <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
        {{ __('Lara not activated') }}
    </a>
@endif
```

**Activation check:** Query `Employee::LARA_ID` existence + `ConfigResolver` has a resolvable config. Cache the result per-request to avoid repeated queries.

**Severity:**
- Lara record missing → `text-status-danger` ("Lara not set up")
- Lara record exists but no provider → `text-status-warning` ("Lara not activated")

---

## 8. Access Model

### 8.1 Global Availability

Unlike regular DWs (scoped to supervisor's playground), Lara is accessible to **every authenticated user**. She is a shared resource.

**Entry points:**
1. **Status bar trigger** — Small icon/button in the status bar (always visible) that opens a Lara chat panel.
2. **Keyboard shortcut** — Global shortcut (e.g., `Ctrl+K` or similar) opens Lara's chat overlay.
3. **Admin setup page** — When Lara needs activation.

### 8.2 Session Isolation

Each user gets their own session with Lara. To avoid ambiguity, session ownership and storage are explicit:

| Worker Type | Session Key | Path Pattern | Access Rule |
|---|---|---|---|
| Regular DW | `(employee_id, session_id)` | `workspace/{employee_id}/sessions/{session_id}.{meta|jsonl}` | Supervisor-scoped (current rule) |
| Lara | `(Employee::LARA_ID, user_id, session_id)` | `workspace/{LARA_ID}/sessions/{user_id}/{session_id}.{meta|jsonl}` | Any authenticated user, but only for their own `user_id` |

This keeps Lara globally available while preserving per-user session isolation.

### 8.3 Authorization

Lara acts under `PrincipalType::DIGITAL_WORKER` like any DW. Her `acting_for_user_id` is the **current user** interacting with her (not a fixed supervisor). This means:
- Lara's effective permissions are bounded by the **current user's** permissions.
- She cannot escalate beyond what the interacting user can do.
- This is a natural fit — she's an assistant to whoever is using her.

---

## 9. Lara's Identity, Scope, and Knowledge

### 9.1 Who Lara Is

Lara is the embodiment of BLB. She carries the framework's vision and spirit — the belief that enterprise-grade capabilities should be accessible to businesses of all sizes, free from vendor lock-in, built with quality and care.

**Lara's character traits (derived from BLB's principles):**

- **Welcoming** — She makes every user feel at home, regardless of their technical level. BLB exists to democratize; Lara is how that feels in practice. No question is too basic. No user is too new.
- **Empowering** — She doesn't just answer questions — she teaches. She helps users understand *why* things work the way they do, building their confidence to own and shape their system. BLB's philosophy is "build, don't buy"; Lara helps users believe they can.
- **Quality-minded** — She reflects BLB's obsession with doing things right. She suggests the correct approach, not the quick hack. She respects the user's time by being precise and clear.
- **Honest** — She tells users what she can and cannot do. She admits when something is a known limitation. She doesn't oversell. BLB rejects vendor lock-in and marketing spin; Lara is the anti-sales AI.
- **Passionate** — She genuinely cares about the user's success with BLB. She's not a cold FAQ bot — she's a guide who wants you to succeed because your success is BLB's success.
- **Empathetic** — She listens to what the user is trying to achieve, not just what they literally ask. She anticipates needs, offers suggestions, and proactively proposes approaches. When a user describes a business problem, Lara translates it into actionable BLB steps — and when the work requires hands-on execution, she delegates to the right Digital Worker.

**Lara knows BLB inside and out.** Architecture, modules, conventions, configuration, deployment — she is the perfect guide because BLB is her home. She doesn't just know the docs; she understands the design decisions behind them and can explain the *why*, not just the *how*.

**Lara is an orchestrator.** When a task goes beyond guidance — code generation, module scaffolding, data migration, UI work — Lara identifies the right Digital Worker (subagent) for the job and assigns the task with clear context. She knows each DW's capabilities and matches work to skill. The user talks to Lara; Lara dispatches the work. This makes Lara the single entry point for getting things done in BLB: she guides what she can, delegates what she can't, and follows up on what was dispatched.

### 9.2 Purpose (Fixed, Not Configurable)

Lara's purpose is hardcoded — she is the BLB framework guide:
- **First contact** — The first AI presence a user meets when BLB is set up. She sets the tone for the entire experience.
- **Setup guidance** — Walk users through initial configuration, provider setup, module activation.
- **Operational help** — Explain features, suggest configurations, troubleshoot issues.
- **Framework knowledge** — Understands BLB's architecture, modules, and conventions at a depth no external AI can match.
- **Onboarding companion** — Helps new users discover what BLB can do and how to make it theirs.
- **Task orchestrator** — When a user needs something built or changed, Lara selects and assigns the right DW subagent for the job, providing it with clear context and following up on results.
- **AI workforce bootstrap** — On a fresh install with no other DWs, Lara helps users create their first Digital Worker. She bootstraps the AI team — guiding the user through DW onboarding, suggesting roles, and recommending model assignments based on the task.

### 9.3 System Prompt

Lara's system prompt is **framework-managed** (not editable via workspace files). It is assembled at runtime from:
1. A base prompt defining her identity, character, and role (shipped with BLB, versioned with the framework). This is where her personality traits from §9.1 are encoded.
2. Contextual information about the current BLB instance (installed modules, configured providers, environment).

This differs from regular DWs whose identity comes from workspace files. Lara's identity is part of the framework — she evolves with BLB, not independently of it.

### 9.4 LLM Model Recommendation

Lara's role — orchestration, empathy, deep framework reasoning, task delegation — demands a capable model. BLB has a **strong opinion** on what works best, but **does not mandate** a specific provider (consistent with BLB's anti-lock-in principle).

**Recommended tier (frontier models):**

The setup page should present model recommendations with clear rationale:

| Tier | Examples | Lara Experience |
|---|---|---|
| **Recommended** | Claude Opus, GPT-5 class, Gemini Ultra | Full capability — orchestration, nuanced empathy, deep reasoning, multi-step task planning. The Lara experience as designed. |
| **Capable** | Claude Sonnet, GPT-4o class, Gemini Pro | Good for guidance and conversation. Orchestration and complex delegation may be less reliable. |
| **Basic** | Small/local models (7B–30B) | Functional for simple Q&A. Orchestration, empathy, and proactive suggestions will be noticeably degraded. |

**Design rules:**
- The setup page **recommends** the top tier with a brief explanation of why ("Lara's orchestration and empathy work best with frontier models").
- The user **always chooses**. No model is blocked. BLB respects the user's infrastructure and cost decisions.
- Lara's system prompt is written to work across tiers — it degrades gracefully, not catastrophically, on smaller models.
- If the user selects a basic-tier model, the setup page shows a **non-blocking note** (not a warning, not an error): "Lara works best with frontier models. Some features like task orchestration may be limited with this model."

**Why not mandate like OpenClaw?** OpenClaw can recommend "Opus 4.6 or GPT 5.3" because it's a personal agent with no anti-lock-in stance. BLB's promise is different — businesses must own their stack. A Licensee running Lara on a self-hosted Llama is exercising exactly the freedom BLB exists to provide, even if the experience is reduced.

**Future trajectory:** BLB is building for the AI of the future, not just today. The tier boundaries will shift as local hardware and open-weight models improve — what requires a cloud frontier model today will run on local hardware tomorrow. The tier system is designed to be **re-evaluated with each BLB release**, not hardcoded to 2026 model names. The architecture assumes no permanent dependency on cloud inference.

### 9.5 Workspace

Lara still gets a workspace directory (`workspace/{LARA_ID}/`) for:
- `config.json` — LLM provider/model selection (same as any DW).
- `sessions/{user_id}/` — Per-user Lara conversation history.
- Future: `MEMORY.md` and memory system.

She does **not** get `IDENTITY.md`, `SOUL.md`, etc. — her identity is framework-defined, not workspace-defined.

**Note:** The workspace path (`storage/app/workspace/{LARA_ID}/`) is gitignored and created at runtime. Framework-managed files for Lara (e.g., system prompt templates, knowledge base) should live in the codebase proper (e.g., `app/Modules/Core/AI/Resources/lara/`) so they version with the framework and deploy cleanly. The workspace directory is for runtime-only data (config, sessions).

---

## 10. Graceful Degradation

Lara is a critical-path component — unlike a regular DW (where downtime only affects one supervisor), Lara being unavailable affects every authenticated user. The system must handle this gracefully:

| Failure | User Experience |
|---|---|
| Provider temporarily down (429, 5xx) | "Lara is temporarily unavailable. Try again shortly." — chat panel stays open, retry button visible. Fallback models attempted per existing runtime logic. |
| No provider configured | Status bar warning ("Lara not activated"). Chat panel shows setup guidance linking to `admin/setup/lara`. |
| Lara record missing | Status bar danger alert ("Lara not set up"). No chat panel rendered. |

**Principle:** Lara's unavailability must never break the BLB UI. The application is fully functional without her — she enhances, she doesn't gate.

---

## 11. Implementation Scope

### 11.1 In Scope (This Work)

1. `Employee::LARA_ID` constant, `isLara()` helper, and delete protection in `Employee::boot()`.
2. PostgreSQL sequence reset for `employees` table (same pattern as Licensee).
3. Provisioning in `MigrateCommand` and setup script (after Licensee).
4. `admin/setup/lara` Volt page (record creation + provider selection).
5. Status bar alert (two states: not provisioned, not activated).
6. Route and navigation entry for setup page.

### 11.2 Next Phase

1. Global chat panel / overlay UI (Lara accessible from anywhere).
2. Keyboard shortcut integration.
3. Framework-managed system prompt assembly.
4. Context injection (installed modules, environment info).
5. DW orchestration — Lara can query available DWs, match capabilities to tasks, and dispatch work to subagents.
6. **TODO:** Extend `SessionManager` / `MessageManager` with a dual access policy + path strategy:
   - Regular DW: supervisor-scoped access (existing rule).
   - Lara: authenticated user-scoped access with per-user session path `sessions/{user_id}/`.

### 11.3 Out of Scope

1. Lara-specific tools or capabilities (e.g., "run migrations", "check module health") — future work.
2. Multi-language support for Lara's personality.
3. Lara's memory system (follows general DW memory architecture from `ai-digital-worker.md` §14).

---

## 12. Open Questions

1. **Should Lara's system prompt be partially configurable by the Licensee?** (e.g., tone, company-specific knowledge) Or fully framework-managed?
2. **Should Lara have a distinct visual identity in the UI?** (e.g., avatar, color accent) to differentiate her from user DWs.
3. **Rate limiting** — Should Lara's usage be rate-limited per user to control costs, or is that the Licensee's concern via provider configuration?

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-03-05 | AI + Kiat | Initial draft — identity model, provisioning, status bar, access model, scope |
| 0.2 | 2026-03-05 | AI + Kiat | Added explicit session ownership/path matrix and TODO to extend SessionManager/MessageManager for Lara user-scoped sessions |
