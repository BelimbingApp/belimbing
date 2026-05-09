# Livewire Components and Blade Tutorial

**Document Type:** Tutorial
**Purpose:** Learn how standard Livewire class-based components work with Blade in BLB
**Related:** [Livewire Docs](https://livewire.laravel.com/docs), [UI Layout Spec](../architecture/ui-layout.md)
**Last Updated:** 2026-03-10

---

## Overview

This tutorial explains **Livewire components** (class-based) and **Blade** (Laravel's templating engine) as used in Belimbing. By the end you'll understand the two-file structure (PHP class + Blade template), how they connect, and common patterns for state, actions, and binding.

BLB uses **standard Livewire class-based components** — a PHP class in the module namespace and a Blade template in `resources/core/views/livewire/`. This separation lets licensees override templates without touching business logic.

---

## 1. Two files, clear boundary

Every Livewire component is a pair:

| File | Location | Role |
|------|----------|------|
| **PHP class** | `app/Modules/Core/<Module>/Livewire/` or `app/Base/<Module>/Livewire/` | State, actions, data queries |
| **Blade template** | `resources/core/views/livewire/<area>/<name>.blade.php` | Layout, HTML, `wire:*` bindings |

The PHP class `render()` method connects the two by returning `view('livewire.<dot.path>')`.

**Minimal example:**

PHP class (`app/Modules/Core/Geonames/Livewire/Admin1/Index.php`):

```php
<?php

namespace App\Modules\Core\Geonames\Livewire\Admin1;

use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public function save(): void
    {
        // Persist $this->name, flash message, etc.
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.admin.geonames.admin1.index');
    }
}
```

Blade template (`resources/core/views/livewire/admin/geonames/admin1/index.blade.php`):

```blade
<div>
    <input type="text" wire:model="name" />
    <button wire:click="save">Save</button>
</div>
```

- **PHP class:** Public properties are component state; methods are actions (e.g. called by `wire:click="save"`).
- **Blade template:** `wire:model` and `wire:click` bind the view to that state and those actions.
- **`render()` method:** Every class must explicitly return the view since classes live in module namespaces, not the default `App\Livewire` namespace.

---

## 2. The component class

### 2.1 Extending Component and traits

```php
namespace App\Modules\Core\Geonames\Livewire\Admin1;

use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;
```

- Extend `Livewire\Component`.
- Use Livewire traits as needed (e.g. `WithPagination` for paginated lists).

### 2.2 State (public properties)

```php
public string $search = '';
public string $filterCountryIso = '';
```

- Public properties are **reactive state**: when they change (e.g. from `wire:model`), Livewire re-renders the view as needed.
- Type hints are required per BLB conventions.

### 2.3 Passing data to the view

Data that isn't stored as component state (e.g. a paginated query result) is passed as the second argument to `view()` in `render()`:

```php
public function render(): \Illuminate\Contracts\View\View
{
    $query = Admin1::query()->orderBy('name');
    // ... apply filters from $this->search, $this->filterCountryIso ...

    return view('livewire.admin.geonames.admin1.index', [
        'admin1s' => $query->paginate(20),
        'importedCountries' => $countryNames,
    ]);
}
```

- Keys in the array become variables in the Blade template (e.g. `$admin1s`, `$importedCountries`).
- `render()` runs on every request that renders the component, so it's the right place for derived/list data.

### 2.4 Actions (public methods)

Methods are the "controller actions" the view can call:

```php
public function saveName(int $id, string $name): void
{
    $admin1 = Admin1::query()->findOrFail($id);
    $admin1->name = trim($name);
    $admin1->save();
}

public function update(): void
{
    app(Admin1Seeder::class)->run();
    Session::flash('success', __('Admin1 divisions updated from Geonames.'));
}
```

- Call from Blade with `wire:click="saveName({{ $admin1->id }}, name)"` or `wire:click="update"`.
- You can use dependency injection in method parameters; Laravel resolves them.

### 2.5 Lifecycle: `updated*`

To react when a specific property changes (e.g. reset pagination when filters change):

```php
public function updatedSearch(): void
{
    $this->resetPage();
}

public function updatedFilterCountryIso(): void
{
    $this->resetPage();
}
```

- `updated{PropertyName}` is called by Livewire after that property is updated (e.g. by `wire:model`).

### 2.6 Layout attribute

By default, components use the `app` layout. Auth components specify a different layout:

```php
use Livewire\Attributes\Layout;

#[Layout('components.layouts.auth')]
class Login extends Component
{
    // ...
}
```

---

## 3. The Blade template

### 3.1 Root element and slots

The template usually wraps everything in a single root element and can push slots to the layout:

```blade
<div>
    <x-slot name="title">{{ __('Admin1 Divisions') }}</x-slot>

    <!-- Rest of the view -->
</div>
```

- Layouts can use `<x-slot name="title">` to set page title or similar.

### 3.2 Outputting data

- **Escape:** `{{ $variable }}` or `{{ $admin1->name }}`
- **Raw (use only when safe):** `{!! ... !!}`
- **Default:** `{{ $value ?? 'Default' }}`

### 3.3 Control flow

- **Conditionals:** `@if` / `@else` / `@endif`, `@isset` / `@endisset`
- **Loops:** `@foreach` / `@endforeach`, `@forelse` / `@empty` / `@endforelse`

Example:

```blade
@forelse($admin1s as $admin1)
    <tr wire:key="admin1-{{ $admin1->id }}">
        <td>{{ $admin1->name }}</td>
    </tr>
@empty
    <tr><td colspan="5">{{ __('No admin1 divisions found.') }}</td></tr>
@endforelse
```

- Use `wire:key` on list items so Livewire can track them correctly.

### 3.4 Livewire bindings (wire:*)

| Binding | Purpose |
|--------|---------|
| `wire:model="search"` | Two-way bind to component property `$search` |
| `wire:model.live.debounce.300ms="search"` | Same, but update on change with 300ms debounce |
| `wire:click="update"` | Call component method `update()` on click |
| `wire:click="saveName({{ $id }}, name)"` | Call with arguments |
| `wire:loading` / `wire:target="update"` | Show loading state for a specific action |
| `wire:submit="submitForm"` | Call method on form submit |

Example: button that shows loading only for `update`:

```blade
<button wire:click="update" wire:loading.attr="disabled" wire:target="update">
    <x-icon name="heroicon-o-arrow-path" class="w-5 h-5" wire:loading.class="animate-spin" wire:target="update" />
    <span wire:loading.remove wire:target="update">{{ __('Update') }}</span>
    <span wire:loading wire:target="update">{{ __('Updating...') }}</span>
</button>
```

### 3.5 Blade components

You can use any Blade component (e.g. icons, layout, custom UI):

```blade
<x-icon name="heroicon-o-check-circle" class="w-6 h-6" />
<x-slot name="title">...</x-slot>
```

- Components live in `resources/core/views/components/` or are registered in the app.

### 3.6 Embedding Livewire components in Blade

Use `<livewire:name />` tags to embed a Livewire component inside another Blade template:

```blade
<livewire:ai.tools.workspace />
<livewire:settings.delete-user-form />
```

The component name matches the Blade view path: `resources/core/views/livewire/ai/tools/workspace.blade.php` → `ai.tools.workspace`.

---

## 4. Putting it together: request flow

1. **Initial load:** Livewire renders the component. The class is instantiated, `render()` runs with data, and the Blade template is rendered.
2. **User interaction (e.g. types in search):** `wire:model` updates `$search`; if you use `updatedSearch()`, it runs (e.g. `resetPage()`). Livewire re-runs `render()` and re-renders the view.
3. **User clicks "Update":** `wire:click="update"` calls `update()`. The method runs (e.g. runs the seeder, flashes a message). Livewire re-renders so flash messages and new data appear.

The important idea: **state and logic live in the PHP class; the Blade template only displays data and triggers actions.**

---

## 5. File location and routing

### PHP class placement

Classes are placed in the module that owns the domain logic:

```
app/Modules/Core/Geonames/Livewire/Admin1/Index.php      → Geonames module
app/Modules/Core/Company/Livewire/Companies/Index.php     → Company module
app/Base/Authz/Livewire/Roles/Index.php                   → Authz (Base) module
```

### Blade template placement

Templates stay in `resources/core/views/livewire/` with a path that matches the component name:

```
resources/core/views/livewire/admin/geonames/admin1/index.blade.php
resources/core/views/livewire/companies/index.blade.php
resources/core/views/livewire/admin/roles/index.blade.php
```

### Routing

Routes use explicit class references:

```php
use App\Modules\Core\Geonames\Livewire\Admin1\Index;

Route::get('/admin/geonames/admin1', Index::class)
    ->name('admin.geonames.admin1.index');
```

### Component auto-discovery

BLB auto-discovers all Livewire components via `ComponentDiscoveryService`. It scans `app/Base/*/Livewire/` and `app/Modules/*/*/Livewire/`, parses each class's `view()` call to derive the component name, and registers it with Livewire. No manual registration needed.

---

## 6. Quick reference

| Need | Where | How |
|------|-------|-----|
| Reactive state | PHP class: `public $prop` | Use `wire:model="prop"` or set in methods |
| Data for list/table | PHP class: `render()` second arg | Use `$key` in Blade |
| Button/link action | PHP class: `public function action()` | `wire:click="action"` or `wire:submit="action"` |
| Run when property changes | PHP class: `updatedPropertyName()` | Automatic after property update |
| Show in view | Blade: `{{ $var }}`, `@if`, `@foreach` | Normal Blade |
| Loading state | Blade: `wire:loading`, `wire:target="action"` | Livewire directives |
| Embed sub-component | Blade: `<livewire:name />` | Component name = view path with dots |

---

## 7. Further reading

- [Livewire Docs (official)](https://livewire.laravel.com/docs) — full API reference.
- [UI Layout Architecture](../architecture/ui-layout.md) — layout zones, sidebar, and component strategy.
