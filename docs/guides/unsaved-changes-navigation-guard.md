# Unsaved-Changes Navigation Guard (Alpine.js + Livewire 4)

Use this pattern when a page must warn the user before navigating away with unsaved edits.

## Key pitfalls

1. **Listen to `alpine:navigate`, not `livewire:navigate`.**
   `wire:navigate` is mapped to Alpine's `x-navigate` directive. Alpine fires `alpine:navigate` and checks `defaultPrevented` *before* calling `navigateTo()`. Livewire then forwards it as `livewire:navigate` — but by then the navigation is already committed. Preventing `livewire:navigate` has no effect on SPA links.

2. **Compute dirty state synchronously in the handler, not via `x-effect`.**
   Alpine effects are microtask-batched. If the user edits a field and immediately clicks a nav link, the `alpine:navigate` event fires synchronously before the effect flush — so any reactive `unsavedChanges` variable is still `false`. Read `$wire` values directly inside the handler.

3. **`$cleanup` is not available in Livewire's bundled Alpine.**
   Use `window.__navGuardCleanup` instead: store a cleanup function on `window` at mount, call it at the top of `x-init` (handles re-mount) and before `Livewire.navigate()` (prevents recursive triggering).

4. **`e.returnValue = ''` (empty string) won't trigger the browser "Leave site?" dialog.**
   Use `e.preventDefault()` *and* a non-empty `e.returnValue`.

## Production-ready template

```blade
<div
    x-data="{
        savedName: @js($savedName),
        savedSql: @js($savedSql),
        unsavedChanges: false,
        skipNextNavigateConfirm: false,
    }"
    @some-saved-event.window="
        savedName = $wire.editName;
        savedSql  = $wire.editSql;
        skipNextNavigateConfirm = false;
    "
    x-init="
        window.__navGuardCleanup?.();
        const isDirty = () => $wire.editName !== savedName || $wire.editSql !== savedSql;
        const beforeUnloadHandler = (e) => { if (isDirty()) { e.preventDefault(); e.returnValue = 'unsaved'; } };
        const navigateHandler = (e) => {
            if (skipNextNavigateConfirm) { skipNextNavigateConfirm = false; return; }
            if (!isDirty()) return;
            e.preventDefault();
            if (confirm({{ json_encode(__('You have unsaved changes. Leave anyway?')) }})) {
                window.__navGuardCleanup?.();
                const url = e.detail.url;
                Livewire.navigate(typeof url === 'string' ? url : url.toString());
            }
        };
        window.addEventListener('beforeunload', beforeUnloadHandler);
        document.addEventListener('alpine:navigate', navigateHandler);
        window.__navGuardCleanup = () => {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            document.removeEventListener('alpine:navigate', navigateHandler);
            window.__navGuardCleanup = null;
        };
    "
    x-effect="unsavedChanges = $wire.editName !== savedName || $wire.editSql !== savedSql;"
>
```

**On save (PHP):** dispatch a browser event so Alpine can sync `saved*` values:

```php
$this->dispatch('some-saved-event'); // triggers @some-saved-event.window in Alpine
```

**Save/cancel buttons** that should navigate without the guard:

```blade
<x-ui.button @click="$dispatch('allow-next-navigate')" wire:click="save">Save</x-ui.button>
```

```blade
@allow-next-navigate.window="skipNextNavigateConfirm = true"
```

