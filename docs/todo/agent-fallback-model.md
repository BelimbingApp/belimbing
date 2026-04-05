# Agent Backup Model Configuration

## Problem Essence

The agent setup UI (Lara/Kodi) allows selecting one provider and one model. The backend supports ordered fallback via `llm.models[]`, but the UI writes only a single entry — so there is no way to configure a backup model through normal user flows.

## Status

Complete

## Desired Outcome

Each agent's model picker exposes an optional **Backup Model** that can be from the same or a different provider. When the primary fails with a transient error, the runtime falls back to the backup. The conversation thread shows the user what happened — both inline (when the backup was used) and as a persistent notice at the end of the thread suggesting the user may want to switch models.

## Public Contract

### Configuration

- Primary provider + model is required (unchanged).
- Backup provider + model is optional. Can be the same provider with a different model, or a different provider entirely.
- When set, `config.json` writes exactly two entries: `[{provider, model: primary}, {provider, model: backup}]`.
- When cleared, `config.json` reverts to a single entry.
- Maximum two models total — no infinite fallback chain.

### Chat Thread Visibility

Two new UI elements when a backup was used during a conversation turn:

1. **Inline fallback notice** — Shown above the assistant response when the primary failed and the backup succeeded. Format: `"⚠ {primary error}. Switched to {provider}/{model}."` Styled as a compact amber notice (similar to hook_action entries), not a full error bubble.

2. **Thread-level banner** — A persistent notice anchored above the composer at the bottom of the thread. Appears when any message in the current session used a fallback. Communicates that the primary model had issues and suggests the user may want to switch. Example: `"Primary model ({provider}/{model}) failed: {error}. Consider switching to another model."` Dismissible, with a link to the model settings page.

### "Current Configuration" Card

Shows both primary and backup models when configured, with a `Backup` badge on the second entry.

## Design Decisions

- **"Backup" for configuration, "fallback" for runtime** — The UI says "Backup Model" because it's clear to admins. The runtime code keeps `fallback_attempts`, `shouldFallback()` etc. unchanged. No renaming of existing internals.
- **Cross-provider allowed** — The backup picker is a full provider+model selector, independent of the primary. This requires a second set of properties (`backupProviderId`, `backupModelId`) in the trait.
- **Two entries maximum** — `writeConfig` writes at most two entries. The runtime iterates them in order; no code change needed in `ConfigResolver` or the runtimes.
- **Both responses are logged** — Already in place. When primary fails: the failure is captured in `meta.fallback_attempts[]` with provider, model, error, error_type, latency_ms, and diagnostic. When backup succeeds: it becomes the main response with full content and meta. `RunRecorder` persists both.

## Top-Level Components

| Component | Change |
|-----------|--------|
| `ManagesAgentModelSelection` trait | Add `$backupProviderId`, `$backupModelId`, hydration, validation, `writeConfig` with 2 entries, `resolveActiveSelection` returns backup info |
| `llm-provider-model-picker.blade.php` | No change — included twice with different bindings |
| `Lara.php` / `Kodi.php` Livewire | Add `updatedBackupProviderId()` / `updatedBackupModelId()` handlers, pass backup models + backup selection to view |
| `lara.blade.php` / `kodi.blade.php` views | Show backup in "Current Configuration" card; render second picker for backup |
| `assistant-result.blade.php` | Show inline fallback notice when `fallbackAttempts` is non-empty |
| `chat.blade.php` | Add thread-level banner above composer when session has fallback events |

## Phases

### Phase 1 — Trait + Livewire (Backend)

- [x] Add `$backupProviderId`, `$backupModelId` to `ManagesAgentModelSelection`
- [x] `hydrateFromCurrentConfig`: read `models[1]` as backup, resolve its provider ID
- [x] Add `hydrateBackupModel()`: align backup model with backup provider (clear if provider changes or model invalid)
- [x] `writeConfig`: write two entries when backup is set, one entry when not
- [x] `validateProviderAndModel`: add optional backup validation (only when `backupProviderId` is set)
- [x] `resolveActiveSelection`: return `activeBackupProviderName` and `activeBackupModelId`
- [x] `availableBackupModels()`: models for the backup provider (extracted `modelsForProvider()` private helper)
- [x] Lara/Kodi components: `updatedBackupProviderId()` / `updatedBackupModelId()` / `removeBackup()`, extracted `autoSaveIfActivated()` to DRY the save logic
- [x] `clearBackup()`: helper to reset backup state

### Phase 2 — Setup Views (Configuration UI)

- [x] Lara/Kodi views: show backup provider/model in "Current Configuration" card with `Backup` badge
- [x] Add second picker section as a separate card below "Change Model":
  - [x] Label: "Backup Model (Optional)"
  - [x] Helper text: "If the primary model fails, the system will automatically retry with this model."
  - [x] Full provider+model picker via `@include` with `backupProviderId`/`backupModelId` bindings
  - [x] "Clear backup" button when a backup is set

### Phase 3 — Chat Thread Visibility

- [x] **Inline fallback notice** (`assistant-result.blade.php`): Amber notice above response content showing the failed error and which model was used instead. Uses `heroicon-o-arrow-path` icon.
- [x] **Thread-level banner** (`chat.blade.php`): Dismissible amber banner above the composer when any message in the session had non-empty `fallback_attempts`. Shows primary model, error, and "Consider switching" message. Alpine `x-data="{ dismissed: false }"` for dismissal.
- [x] **Streaming path**: No changes needed — `done` event already includes `meta.fallback_attempts`, server-rendered Blade picks it up after `finalizeStreamingRun()`.
