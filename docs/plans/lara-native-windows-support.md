Status: Identified
Last Updated: 2026-05-15
Sources: `docs/architecture/ai/lara.md`, `app/Modules/Core/AI/Tools/BashTool.php`, `app/Modules/Core/AI/Services/Browser/PlaywrightRunner.php`, `app/Modules/Core/AI/Livewire/Concerns/HandlesAttachments.php`
Agents: {copilot/gpt-5.4}

# lara-native-windows-support

## Problem Essence

Lara currently works in environments that provide Unix shell semantics, but native Windows execution still depends on hardcoded Bash and POSIX process behavior. That leaves Lara unable to reliably execute shell work, launch detached helper processes, and use some runtime helpers on Windows 11 without WSL2.

## Desired Outcome

Lara should operate honestly and predictably on native Windows with a supported shell strategy, Windows-safe process launching, and setup guidance that matches the real runtime contract. A Windows operator should be able to provision the required tools, enable Lara, and expect the same core coding-agent surfaces to work without relying on WSL2.

## Top-Level Components

1. **Shell execution contract** — the Lara tool/runtime layer that currently presents Bash as the primary shell execution surface.
2. **Background process launching** — browser, OAuth, and similar flows that need detached child processes and explicit environment propagation.
3. **Runtime helper compatibility** — shell-backed helpers such as binary discovery and redirection-sensitive commands.
4. **Operator-facing setup and architecture docs** — plans, labels, and guidance that tell admins what native Windows support requires.

## Design Decisions

The recommended direction is to keep the user-facing concept as a shell tool while removing the implementation requirement that the shell must be Bash. Lara should execute through a small platform-aware shell abstraction that can resolve an approved shell backend per environment, with native Windows defaulting to PowerShell and optional Git Bash support only where the semantics are intentionally supported.

Detached process launching should stop relying on shell syntax such as inline environment prefixes, `setsid`, `nohup`, and Unix redirection. Instead, Lara should use explicit process APIs and argument lists so Windows and Unix both get truthful, testable launch behavior from the same higher-level contract.

Unix-only helper commands should be treated as compatibility bugs, not operator setup gaps. If a helper only works through `which`, `/dev/null`, or similar assumptions, it should either move to a platform-safe implementation or degrade explicitly with an honest capability message.

Documentation should not continue to describe Lara as depending on `bash` when the intended product contract is "shell or equivalent command-line tools." The docs should describe the supported Windows path, the required binaries, and any limits that still differ from Unix-like environments.

## Public Contract

Native Windows support should expose these guarantees:

1. Lara can execute approved shell commands through a supported Windows-native shell backend.
2. Lara can start and observe required child processes without Unix-only launcher syntax.
3. Lara reports unsupported commands or missing prerequisites clearly instead of failing through shell-not-found behavior.
4. Lara setup documentation tells operators which native Windows prerequisites are mandatory and which shell backend is in use.

## Phases

### Phase 1

Goal: make the Windows support scope complete before changing runtime behavior.

- [ ] Audit every Lara execution path that assumes Bash, POSIX process spawning, Unix redirection, or Linux-only helper commands.
- [ ] Identify which command surfaces must stay shell-compatible across platforms and which can move to direct process invocation.
- [ ] Define the supported native Windows prerequisite set for Lara, including shell backend, Git, PHP, Node, and Bun expectations.

### Phase 2

Goal: establish a platform-aware execution contract for Lara.

- [ ] Introduce a shell backend abstraction that resolves the active shell implementation per environment instead of hardcoding Bash.
- [ ] Refactor Bash-oriented tool metadata, examples, and execution code so the product contract stays truthful on Windows while preserving repo and git workflows.
- [ ] Define how shell quoting, working directory selection, environment inheritance, and streaming semantics behave across Windows and Unix backends.

### Phase 3

Goal: remove native-Windows blockers outside the core shell tool.

- [ ] Replace Unix-only detached launch patterns in browser and OAuth flows with platform-safe process spawning.
- [ ] Replace shell-backed helper lookups that depend on `which`, `/dev/null`, or equivalent Unix-only behavior with platform-safe implementations.
- [ ] Review Lara-adjacent runtime helpers for hidden Unix assumptions and either fix them or document an explicit limitation.

### Phase 4

Goal: make the shipped behavior operable and maintainable.

- [ ] Add focused coverage for Windows-aware shell selection and platform-safe launcher behavior.
- [ ] Update architecture and operator docs so they describe the supported Windows runtime contract instead of a Bash-only assumption.
- [ ] Add setup guidance for native Windows provisioning and note any remaining platform gaps that are still intentionally unsupported.
