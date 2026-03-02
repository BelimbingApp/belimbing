# HTMX and Blade Tutorial

**Document Type:** Tutorial
**Purpose:** Learn how HTMX drives dynamic interactions with Blade templates in this project
**Related:** [HTMX Interaction Contract](../architecture/htmx-interaction-contract.md), [HTMX Docs](https://htmx.org/docs/)
**Last Updated:** 2026-03-02

---

## Overview

This tutorial explains how BLB uses **HTMX** with **Blade** controllers and view templates. By the end you'll understand the structure of a controller action, the corresponding Blade view, how HTMX handles dynamic updates, and the common patterns for search, inline editing, and form submission.

---

## 1. The BLB pattern: Controller + Blade + HTMX

BLB replaced Livewire Volt single-file components with explicit HTTP controllers and Blade templates wired together via HTMX attributes. The responsibilities are separated across three files:

| File | Role |
|------|------|
| **Controller method** | Validates input, queries data, returns a view |
| **Blade view** | Full-page HTML with HTMX attributes |
| **Blade partial** | Fragment HTML for HTMX swap targets (no `<html>`/`<body>`) |

The HTMX attribute on an element declares *what* HTTP request to make and *where* to insert the response — no JavaScript required.

---

## 2. Controller structure

Controllers live in `app/Modules/Core/{Module}/Controllers/` (no `Http/` layer). They use the `InteractsWithHtmx` trait from `app/Base/Htmx/`:

```php
namespace App\Modules\Core\User\Controllers;

use App\Base\Htmx\Concerns\InteractsWithHtmx;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController
{
    use InteractsWithHtmx;

    /** Full-page user list. */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    /** HTMX table fragment for search. */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->paginate(15)
            ->withQueryString();

        return view('users.partials.table', compact('users', 'search'));
    }
}
```

Two methods:
- `index` returns the **full-page** view (initial load, direct URL).
- `search` returns a **partial** view — only the fragment HTMX will swap in.

---

## 3. Full-page view (`users/index.blade.php`)

```blade
<x-layouts.app>
    <div class="p-6">
        <h1>Users</h1>

        {{-- Search input sends HTMX GET to the search endpoint --}}
        <input
            type="text"
            name="search"
            value="{{ $search }}"
            hx-get="{{ route('admin.users.index.search') }}"
            hx-trigger="input changed delay:300ms"
            hx-target="#users-list"
            hx-push-url="false"
            placeholder="Search…"
        />

        {{-- HTMX swap target --}}
        <div id="users-list">
            @include('users.partials.table')
        </div>
    </div>
</x-layouts.app>
```

Key HTMX attributes:

| Attribute | Purpose |
|-----------|---------|
| `hx-get` | URL of the HTMX endpoint to call |
| `hx-trigger` | When to fire (here: 300 ms after input stops) |
| `hx-target` | CSS selector of the element to replace |
| `hx-push-url="false"` | Don't update the browser URL (fragment update) |

---

## 4. Partial view (`users/partials/table.blade.php`)

```blade
<div id="users-list">
    <table>
        <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <form
                            method="POST"
                            action="{{ route('admin.users.destroy', $user) }}"
                            onsubmit="return confirm('Delete {{ $user->name }}?')"
                        >
                            @csrf
                            @method('DELETE')
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $users->links() }}
</div>
```

Rules for partials (from the [HTMX Interaction Contract](../architecture/htmx-interaction-contract.md)):
- **Single root element** — the element's `id` must match `hx-target`.
- **No `<html>`/`<head>`/`<body>`** — it's a fragment.
- **Lives in a `partials/` subdirectory** of the module view directory.

---

## 5. Form submission

Standard HTML forms with `@csrf` and `@method`. No JavaScript required.

```blade
<form method="POST" action="{{ route('admin.users.store') }}">
    @csrf

    <input type="text" name="name" value="{{ old('name') }}" />
    @error('name') <span>{{ $message }}</span> @enderror

    <input type="email" name="email" value="{{ old('email') }}" />
    @error('email') <span>{{ $message }}</span> @enderror

    <button type="submit">Create</button>
</form>
```

The controller validates and redirects:

```php
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'name'  => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
    ]);

    User::query()->create($validated);

    return redirect()->route('admin.users.index');
}
```

Validation errors are returned automatically by Laravel and rendered with `@error`.

---

## 6. Inline field editing

HTMX patches a single field without a full page reload.

**In the view:**
```blade
<form
    hx-patch="{{ route('admin.users.update-field', $user) }}"
    hx-target="#field-name"
    hx-swap="outerHTML"
>
    @csrf
    <input type="hidden" name="field" value="name" />
    <input
        type="text"
        name="value"
        value="{{ $user->name }}"
        hx-trigger="change"
    />
</form>
<span id="field-name">{{ $user->name }}</span>
```

**Controller:**
```php
public function updateField(Request $request, User $user): RedirectResponse
{
    $field = $request->input('field');
    $value = $request->input('value');

    match ($field) {
        'name'  => $user->update(['name' => $request->validate(['value' => 'required|string|max:255'])['value']]),
        'email' => $user->update(['email' => $request->validate(['value' => 'required|email'])['value']]),
        default => abort(422),
    };

    return back();
}
```

---

## 7. HTMX response headers

The `HtmxResponse` builder (from `app/Base/Htmx/HtmxResponse.php`) sends HX-* headers:

```php
use App\Base\Htmx\HtmxResponse;

return response()
    ->view('users.partials.table', compact('users'))
    ->withHeaders(
        HtmxResponse::make()
            ->trigger('blb:flash', ['message' => 'User saved.'])
            ->toHeaders()
    );
```

Alpine.js listens for `blb:flash` on the document and shows a toast notification.

---

## 8. Migration reference: Volt → HTMX

| Volt pattern | HTMX equivalent |
|---|---|
| `wire:model.live.debounce.300ms` | `hx-get` + `hx-trigger="input changed delay:300ms"` + `hx-target` |
| `wire:submit` | `<form method="POST" action="...">` + `@csrf` |
| `wire:model` on input | `name="field" value="{{ old('field') }}"` |
| `wire:navigate` | Remove attribute — plain `<a href>` |
| `wire:click="delete(id)"` + `wire:confirm` | `<form method="POST" onsubmit="return confirm(...)">@csrf @method('DELETE')` |
| Component `$this->dispatch('event', data)` | `HtmxResponse::make()->trigger('event', data)` header |
| `WithPagination` | `->paginate(15)->withQueryString()` |

---

## Related

- [HTMX Interaction Contract](../architecture/htmx-interaction-contract.md) — 7 policies governing all HTMX usage in BLB
- [HTMX Docs](https://htmx.org/docs/) — official documentation
- [Alpine.js Docs](https://alpinejs.dev/start-here) — for client-side interactivity
