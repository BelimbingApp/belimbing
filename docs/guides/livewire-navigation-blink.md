# Livewire Navigation Blink

BLB uses Livewire `wire:navigate` for SPA-like navigation. The shell can feel
instant only if all persistent chrome keeps the same visual state during the
single frame where Livewire swaps in the next page body and before Alpine has
finished initializing that new body.

This guide documents the page-blink investigation from the island-shell work:
BLB made Lara chat and the sidebar persist across `wire:navigate` so navigation
only swaps the main body. It covers the actual root cause found after that
change and the rules to follow when changing the shell.

## What the user saw

Clicking a sidebar menu item caused a very fast page blink. It looked like a
full page refresh even though browser tracing showed the navigation was still a
`fetch`/SPA navigation.

Earlier checks ruled out several visible artifacts:

- no full hard reload;
- no blank white frame;
- no Livewire progress bar after `navigate.show_progress_bar = false`;
- no sidebar/top/status DOM replacement after those regions were wrapped in
  `@persist`;
- no persistent chat teardown after Lara chat was parked back into its persist
  home before navigation.

## Why `@persist` alone was not enough

Livewire navigation is page-level. It parses the target page, updates `<html>`
attributes, replaces `document.body`, and then puts `@persist` nodes back into
matching persist slots.

That means `@persist` preserves a node, but it does not preserve every piece of
client-owned shell state that is normally applied by Alpine during `x-init` or
`x-bind`. During the swap frame, the new body exists before Alpine has fully
re-applied those bindings.

The important rule:

> Persistent chrome must have its visual state restored at Livewire's navigation
> swap hook, not one frame later in `livewire:navigated` or
> `requestAnimationFrame`.

## Actual root cause

A Playwright frame probe of `Companies → Addresses` found a one-frame layout
gap at the swap point:

| Moment | Desktop sidebar wrapper | `<main>` left | `<main>` width |
|--------|-------------------------|---------------|----------------|
| Before navigation | `224px` | `224px` | `1216px` |
| Swap frame before fix | `~271.5px` natural width | `271.5px` | `1168.5px` |
| After Alpine initialized | `224px` | `224px` | `1216px` |

The desktop sidebar wrapper is not itself the persisted sidebar; it is the flex
wrapper that owns the drag-resizable width. Its width is applied by Alpine via
`:style="'width: ' + sidebarWidth + 'px'"`. When Livewire inserted the new body,
that wrapper briefly had no inline width, so it expanded to its natural content
width and pushed `<main>` sideways for one frame.

The same swap removed client-owned `<html>` state because Livewire replaced the
attributes with the server-rendered `<html>` attributes. Two states mattered:

- `class="dark"`, which is normally applied client-side from `localStorage` by
  the top bar;
- `data-alpine-ready`, which had been used as the guard for the persisted-chrome
  `x-cloak` stripper.

In dark mode this could create a light/dark flash. For `x-cloak`, it meant the
guard could be disabled exactly when the persisted-chrome protection was needed.

## Shipped solution

The shell now reapplies client-owned visual state during the navigation swap:

- `resources/core/js/shell-navigation.js`
  - `globalThis.__blbAlpineReady` records Alpine readiness in JS, so the state
    survives Livewire's `<html>` attribute replacement.
  - `globalThis.blbShellNavigation.wire()` owns the one-time `wire:navigate`
    listeners and persisted-chrome `x-cloak` observer.
  - `globalThis.blbShellNavigation.applyClientHtmlState()` reapplies dark mode
    from `localStorage`.
  - `globalThis.blbShellNavigation.applyDesktopSidebarWidth()` reapplies the
    sidebar wrapper width from `localStorage` using the same persisted
    `sidebarRail` and `sidebarWidth` values that Alpine uses.
  - `globalThis.blbShellNavigation.prepareLaraChatForNavigate()` parks the
    persisted Lara chat instance without moving it visually.
  - `globalThis.blbShellNavigation.applyLaraChatShellState()` restores Lara chat
    into the new page's active overlay, docked, fullscreen, or mobile target at
    swap time.
  - `globalThis.blbShellNavigation.applyNavigateSwapShellState()` runs all
    shell-state repairs.
- `resources/core/js/shell-layout.js`
  - `globalThis.blbAppShell()` owns the app-shell Alpine state and actions that
    were previously inline in the Blade layout: sidebar sizing, Lara chat mode
    state, dock resizing, hotkey actions, and chat teleporting between targets.
- `resources/core/views/components/layouts/app.blade.php`
  - the body passes server-owned activation state into
    `window.blbAppShell({ laraActivated: ... })`;
  - the desktop sidebar width wrapper has `data-blb-sidebar-width-shell` so the
    early JS repair can target it directly;
  - `livewire:navigating` uses `event.detail.onSwap(...)` to run the shell repair
    before paint;
  - active-menu marking, active-group expansion, and sidebar scroll restoration
    also run at swap time, with `livewire:navigated` kept as a fallback;
  - the `x-cloak` MutationObserver now gates on `window.__blbAlpineReady`, not
    on an `<html>` attribute that Livewire can temporarily remove.

## Lara chat-specific blink

After the general shell blink was fixed, a remaining blink appeared only when
Lara chat was open. That was a different artifact.

The chat itself is a single persisted Livewire instance. The layout moves that
instance between mode targets:

- overlay: a fixed floating card;
- docked: an inline right-side `<aside>` that changes the flex layout;
- fullscreen: an absolute panel over `<main>`;
- mobile: a full-screen takeover.

To survive `wire:navigate`, the chat must be inside its persisted home
(`#lara-chat-home`) before Livewire swaps the body. The earlier implementation
did this by simply appending `#lara-chat-instance` back to the home during
`livewire:navigating`. That preserved the Livewire component, but it created a
visible parking frame: the chat briefly appeared at the home location instead of
its overlay/dock/fullscreen geometry. In docked mode, the dock could also leave
the flex layout for a frame, making `<main>` resize.

The fix is to separate **persistence parking** from **visual movement**:

1. On `livewire:navigating`, capture the chat's current `getBoundingClientRect()`
   and apply temporary `position: fixed` geometry matching that rectangle.
2. Append the fixed chat element back to `#lara-chat-home` so Livewire's persist
   mechanism can keep the Livewire instance alive.
3. At `event.detail.onSwap`, show the correct new shell target immediately,
   append the chat into that target, then restore the element's previous inline
   style.

To the user, the chat never moves during the parking step. To Livewire, the chat
is back in its persist slot before the body swap. This is why the solution is not
an animation or a delay; it is an atomic state repair at the same navigation swap
boundary as the sidebar and dark-mode repairs.

## Verification contract

For a representative navigation such as `Companies → Addresses`, verify at the
navigation `onSwap` moment, not only after `livewire:navigated`:

- desktop sidebar wrapper width remains the configured width, e.g. `224px`;
- `<main>` left and width remain stable, e.g. `224px` and `1216px` at a
  `1440 × 900` viewport;
- in dark mode, `<html class="dark">` is already restored at `onSwap`;
- `window.__blbAlpineReady` remains true even if `data-alpine-ready` is briefly
  absent from `<html>`;
- no chrome outside `<main>` receives a visible `x-cloak` hide frame.
- with Lara chat open, the chat may be in `#lara-chat-home` during
  `livewire:navigating`, but only with temporary fixed-position geometry equal to
  its pre-navigation rectangle;
- by `onSwap`, the chat is back in the correct new target (`laraOverlayTarget`,
  `laraDockTarget`, `laraFullscreenTarget`, or `laraMobileTarget`) and the
  temporary fixed-position style has been removed;
- in docked chat mode, the right dock remains visible and `<main>` keeps the same
  width at `onSwap`.

Manual browser judgment is still useful, but the decisive test is a frame-level
probe around `alpine:navigating`/`onSwap`. Waiting until `livewire:navigated`
can miss the bad frame because Alpine has already corrected it.

## Cleanup guidance

Do not remove a shell change merely because it was not the final root cause. The
investigation found several independent artifacts, and the remaining shipped
changes each protect a real behavior:

- keep the persisted sidebar/top/status/chat islands; they prevent chrome DOM
  replacement and state loss;
- keep the Lara chat fixed-geometry parking step; simple append-to-home parking
  preserves the Livewire instance but reintroduces the chat-open blink;
- keep the disabled Livewire progress bar unless the app becomes slow enough for
  a progress indicator to help more than it distracts;
- keep the persisted-chrome `x-cloak` observer, but keep it scoped outside
  `<main>` so page content still gets normal Alpine FOUC protection;
- keep sidebar scroll restoration and active-menu recomputation because the
  sidebar tree is persisted and no longer re-rendered on every page;
- keep the swap-time shell-state repair because it fixes the actual blink.

The structural shell cleanup is complete: app-shell Alpine state lives in
`resources/core/js/shell-layout.js`, and navigation/chrome repair lives in
`resources/core/js/shell-navigation.js`. Future cleanup should keep this
boundary: Blade owns server-rendered shell markup and server-provided state;
the JS modules own client shell behavior and `wire:navigate` coordination.
