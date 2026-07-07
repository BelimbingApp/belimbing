# docs/plans/security-hardening.md

Status: In progress — response-header/CORS, proxy trust, AI shell code gates, SSRF pinning, media stored-XSS hardening, and secure-defaults/CI are code-complete; eBay webhook hardening remains pending in the commerce repo, with shell ops, media authz, and nested-repo CI residuals tracked below.
Last Updated: 2026-07-07
Sources: Audit of `app/`, `routes/`, `config/`, `bootstrap/app.php`, `Caddyfile`, `.env.example` (vendor/ excluded). Root `AGENTS.md` for design principles.
Agents: claude/claude-fable-5, codex/gpt-5

## Problem Essence

Belimbing inherits Laravel's safe defaults (Eloquent binding, encrypted provider
credentials, CSRF-on, login throttling), but three surfaces carry real risk: an AI
agent that can execute arbitrary shell commands, outbound web-fetch tooling exposed
to SSRF, and a missing edge/response-hardening layer. A prompt injection or a leaked
example config turns several of these into server compromise or account takeover.

## Desired Outcome

Each ranked finding below is either fixed or has an explicit, config-gated control
with test evidence. "Done" means: the Bash tool cannot be an unsandboxed RCE path in
production, outbound fetches cannot reach internal targets via rebinding/redirects,
the eBay webhook authenticates callers, every response carries hardening headers, and
insecure defaults cannot ship to production unnoticed. No finding is closed without a
test or a documented operator control.

## Top-Level Components

- **AI tool sandbox & gating** — `BashTool`, `ShellCommandRunner`, `AgentToolRegistry` capability enforcement, `DetachedProcessLauncher`.
- **Outbound fetch safety** — `UrlSafetyGuard`, `WebFetchService` (SSRF).
- **Edge & response hardening** — `SecurityHeaders` middleware + `config/security.php`, `Caddyfile` header snippet, `config/cors.php`. *(Delivered.)*
- **External-caller authentication** — `EbayAccountDeletionController` signature verification.
- **Secure defaults & supply chain** — `.env.example`, `config/session.php`, CI dependency/secret scanning.

## Design Decisions

Three calls are genuinely contested; the rest are mechanical and live in Phases.

**1. Bash tool disposition (finding C1).** Options: (a) keep capability-only gating as
today; (b) add a production kill-switch plus an OS-level sandbox (least-privilege user
/ container); (c) remove the tool. (a) leaves an LLM-chosen command running as the web
user — one prompt injection from RCE, which fails the honesty/UX bar of shipping a
control that looks safer than it is. (c) removes a legitimate operator capability.
**Recommend (b):** the tool stays, but requires an explicit per-environment opt-in in
addition to the capability, runs as a distinct least-privileged OS principal, and
audit-logs every command. This keeps the deep-module boundary (one shell entry point)
while making the dangerous default off, matching root `AGENTS.md` on strategic cost.

**2. SSRF fix approach (finding H2).** Options: (a) tighten the existing
validate-then-fetch flow with more blocklists; (b) resolve the host once, validate the
resolved IP, and connect to that pinned IP so validation and connection agree, with
redirects disabled and re-validated manually. (a) cannot close the DNS-rebinding
TOCTOU — a second resolution at fetch time defeats any blocklist. **Recommend (b):**
pin the validated address for the connection and treat each redirect `Location` as a
fresh URL through `UrlSafetyGuard`. This is the only option that removes the rebinding
and redirect bypasses rather than narrowing them.

**3. CSP strictness (finding H4, delivered).** Options: (a) strict `script-src 'self'`
with nonces/hashes; (b) a pragmatic policy that permits `'unsafe-eval'`/`'unsafe-inline'`
for scripts and styles while locking down `object-src`, `base-uri`, `form-action`, and
`frame-ancestors`; (c) no CSP. The app is TALL-stack: Alpine evaluates expressions via
`Function()` (needs `'unsafe-eval'`), the head ships a bootstrap `<script>`, and inline
`style=""` attributes are everywhere — (a) would require a broad refactor (nonce
threading through every inline site) and breaks Vite dev. (c) forgoes clickjacking and
injection-sink protection. **Chose (b):** it hardens the directives that do not depend
on inline execution and ships today without breaking the running app; tightening
`script-src` to nonces is a tracked follow-up. The whole policy is config-driven with a
Report-Only switch so operators can trial a stricter policy before enforcing it.

## Public Contract

- `config/security.php` — env-tunable master switch (`SECURITY_HEADERS_ENABLED`), static
  headers (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy), conditional
  HSTS (`SECURITY_HSTS_ENABLED`, TLS-only), and a directive-array CSP with
  `SECURITY_CSP_ENABLED` / `SECURITY_CSP_REPORT_ONLY`.
- `SecurityHeaders` middleware (web group) — sets each header only when absent, so a
  controller may set a stricter per-response value (e.g. a locked-down CSP on streamed
  media) and keep it.
- `Caddyfile` `(security_headers)` snippet — mirrors the headers as defaults (`?` prefix)
  for static files FrankenPHP serves without touching PHP; must stay in sync with
  `config/security.php`.
- `config/cors.php` — closed by default (no allowed origins), `api/*` scoped, credentials
  off; grant exact origins via `CORS_ALLOWED_ORIGINS`.

## Phases

Ranked by severity. Tick only when truly done; completed lines carry `{agent}/{model}`.

### Phase 1 — Edge & response hardening (H4 + CORS)
Goal: every dynamic and static response carries hardening headers; cross-origin access is closed by default.
Evidence: `tests/Feature/Foundation/SecurityHeadersTest.php` (6 passing, 16 assertions); `php artisan config:show security`; Pint clean.

- [x] Add `config/security.php` (headers, HSTS, directive-array CSP; all env-tunable) — claude/claude-fable-5
- [x] Add `SecurityHeaders` middleware (nosniff, X-Frame-Options DENY, Referrer-Policy, Permissions-Policy, conditional HSTS, CSP; never clobbers existing headers) and register in the web group — claude/claude-fable-5
- [x] Add `Caddyfile` `(security_headers)` snippet with `?`-default headers, imported by both site blocks, for FrankenPHP-served static files — claude/claude-fable-5
- [x] Add closed-by-default `config/cors.php` (no origins, `api/*`, credentials off) — claude/claude-fable-5
- [x] Prove behaviour with a middleware test (static headers, CSP hardening directives, no-clobber, report-only, TLS-gated HSTS, disabled pass-through) — claude/claude-fable-5
- [ ] Follow-up: pilot a nonce-based `script-src` under `SECURITY_CSP_REPORT_ONLY=true`, then tighten and enforce.

### Phase 2 — Client-spoofed IP / throttle bypass (C2)
Goal: `request()->ip()` reflects the real client so login throttling and IP audit logs cannot be defeated by a forged `X-Forwarded-For`.
Scope: `bootstrap/app.php` `trustProxies`, verify against `Login::throttleKey()`.
Evidence: `tests/Feature/Foundation/TrustedProxiesTest.php` (2 passing); `.env.example` documents `TRUSTED_PROXIES`.

- [x] Replace `trustProxies(at: '*')` with a `TRUSTED_PROXIES`-driven list defaulting to loopback + private ranges (correct for same-host Caddy / cloudflared); `*` remains available only when the fronting proxy strips inbound forwarded headers — claude/claude-fable-5
- [x] Add a test asserting a forged forwarded header from an untrusted peer does not change `request()->ip()` (the throttle-key input) — claude/claude-fable-5
- [ ] Follow-up: if a Cloudflare tunnel terminates remotely in some deployment, document trusting its ranges and preferring `CF-Connecting-IP` there.

### Phase 3 — AI shell tool sandbox & gating (C1)
Goal: the Bash tool cannot be an unsandboxed RCE path; it is off unless an operator knowingly enables it, and every command is audited.
Scope: `BashTool`, `ShellCommandRunner`, `AgentToolRegistry::currentUserCanUse()`, `DetachedProcessLauncher`.
Evidence (opt-in gate): `tests/Feature/AI/BashToolGateTest.php` (3 passing); `config('ai.tools.bash.enabled')`; `.env.example` documents `AI_BASH_TOOL_ENABLED`.

- [x] Gate execution behind an explicit per-environment opt-in (`ai.tools.bash.enabled`, default OFF in production) in addition to `admin.ai.tool.bash.execute`; enforced as a hard kill-switch in both the sync and streaming execution paths — claude/claude-fable-5
- [x] Verify the actor is threaded through queued/Octane agent runs — the runtime jobs establish it explicitly via `Auth::loginUsingId($…->acting_for_user_id)` (`RunAgentTaskJob`, `RunChatTurnJob`, `RunLaraTaskProfileJob`, `SpawnAgentSessionJob`), so `AgentToolRegistry::currentUserCanUse()` resolves the real actor and fails closed when absent. No ambient-authority gap. — claude/claude-fable-5
- [x] Harden `DetachedProcessLauncher`: extract the detached command-line builder (detachment needs a shell string, so a pure `Process` arg-vector is not viable) and cover it with a test asserting command tokens, env values, and redirect paths are all shell-escaped — claude/claude-fable-5
- [ ] Residual (infra/ops, out of code scope): run the shell backend as a distinct least-privileged OS user / sandbox with a scratch working dir; scope down `-ExecutionPolicy Bypass`. Deferred defense-in-depth: audit-log actor/command/exit-code before execution and add a per-user rate limit.

### Phase 4 — SSRF pinning & redirect re-validation (H2)
Goal: outbound fetches cannot reach internal targets via DNS rebinding or redirects.
Scope: `UrlSafetyGuard`, `WebFetchService`.
Evidence: `tests/Unit/AI/UrlSafetyGuardTest.php`, `tests/Feature/AI/WebFetchSsrfTest.php` (redirect-to-internal, redirect limit, initial-internal, extraction, cURL resolve pinning, unpinnable rebinding fallback — all passing); `ToolCallingTest` still green.

- [x] Add `UrlSafetyGuard::pinnedIpFor()` and connect to that validated public IP via cURL's resolve override (keeps Host/SNI), so the address the guard approved is the address contacted — closing the DNS-rebinding TOCTOU — claude/claude-fable-5
- [x] Treat a DNS hostname that validates but cannot be pinned on the connection-time lookup as blocked, never as an unpinned fallback fetch — codex/gpt-5
- [x] Disable automatic redirects (`allow_redirects => false`) and re-run `validate()` on each resolved `Location` before following, up to the redirect limit — claude/claude-fable-5
- [x] Multi-record hosts where any address is private are already blocked by `validate()`; `pinnedIpFor()` only ever returns a validated public address — claude/claude-fable-5
- [ ] Follow-up: also reject exotic IP encodings (octal/hex/decimal) at the guard boundary for defense in depth.

### Phase 5 — eBay webhook authentication (H1)
Goal: the public, CSRF-exempt deletion endpoint authenticates callers and cannot be used for log injection or disk-fill.
Scope: `EbayAccountDeletionController`, `blb_log_var` usage.

- [ ] Verify the eBay Notification API signature (resolve the `x-ebay-signature` key id, verify the payload) before recording anything.
- [ ] Rate-limit and size-cap the endpoint; store log fields as structured data so a body value cannot inject log lines.
- [ ] Add tests: unsigned/forged POST rejected; valid signature accepted.

### Phase 6 — Media stored-XSS hardening (H3)
Goal: user-uploaded media cannot execute in the app origin.
Scope: `MediaAssetStore::putUploadedFile`, `MediaAssetController::stream`.
Evidence: `tests/Feature/Media/MediaStreamSecurityTest.php` (inline raster, SVG/HTML forced to download, SVG upload rejected, normal upload still stored); existing `tests/Feature/Base/Media/*` still green (49).

- [x] `stream()` serves only a strict raster allowlist (jpeg/png/gif/webp) inline; everything else (SVG, HTML, PDFs, unknown) is forced to download as `application/octet-stream`, with `X-Content-Type-Options: nosniff` and a per-response `default-src 'none'; sandbox` CSP the global middleware preserves — claude/claude-fable-5
- [x] Reject script-capable upload types (SVG, HTML, XHTML/XML) at the `putUploadedFile` boundary so active markup never lands in storage — claude/claude-fable-5
- [x] Filename is escaped by Laravel's RFC 6266 disposition builder, so a crafted filename cannot inject response headers — claude/claude-fable-5
- [ ] Residual (M4): add per-actor authorization to `stream()` beyond the signed URL, and consider a separate cookieless asset host.

### Phase 7 — Secure defaults & supply chain (M1, M2, L3, L5)
Goal: insecure config cannot ship to production silently; dependencies and secrets are scanned in CI.
Evidence: `tests/Feature/System/SecurityCheckCommandTest.php` (5 passing); `.github/workflows/security.yml`; `docs/security-advisories.md`.

- [x] `blb:security:check` command fails a production deploy on `APP_DEBUG` on, missing `APP_KEY`, insecure session cookie, or wildcard proxy trust (warns for the same outside production) — a stronger guard than an example default, since the `.env.example` is deliberately a local template — claude/claude-fable-5
- [x] Document `APP_DEBUG`/`SESSION_ENCRYPT` production expectations in `.env.example` and add a documented `SESSION_SECURE_COOKIE` line — claude/claude-fable-5
- [x] Remove the stale `composer.json` audit ignore `PKSA-5jz8-6tcw-pbk4`; current `composer.lock` has `phpunit/phpunit` 12.5.30, beyond the patched 12.5.22 release for CVE-2026-41570 — codex/gpt-5
- [x] Add `.github/workflows/security.yml` running `composer audit`, `bun audit`, and a gitleaks secret scan on push/PR and weekly — claude/claude-fable-5
- [ ] Residual: extend the same CI security scans into the nested gitignored domain repos (blb-people, blb-commerce, extensions/kiat).

## What looked healthy
- SQL access uses Eloquent / bound `whereRaw` placeholders — no injection found in audited code.
- AI provider credentials use `encrypted:array` / `encrypted` casts.
- CSRF protection is on globally with a deliberate, narrow `webhooks/*` exemption.
- Login throttling exists (5 attempts) — its only weakness is the spoofable IP (Phase 2).
- Admin software/deployment routes sit behind `auth` + explicit `authz:` capabilities.
