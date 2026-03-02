# Volt and Blade Tutorial

**Document Type:** Tutorial  
**Purpose:** Learn how Volt single-file components work with Blade in this project  
**Related:** [Livewire Volt Architecture](../architecture/livewire-volt.md), [Livewire Volt Docs](https://livewire.laravel.com/docs/volt)  
**Last Updated:** 2026-02-11

---

## Overview

This tutorial explains **Volt** (Livewire’s single-file component API) and **Blade** (Laravel’s templating engine) as used in Belimbing. By the end you’ll understand the structure of a Volt component, how the PHP “controller” and Blade “view” work together, and common patterns for state, actions, and binding.

---

## 1. One file, two parts

A Volt component is a single file under `resources/views/livewire/`. The file has two clear parts:

| Part | Role | Contents |
|------|------|----------|
| **Top** | Controller-like logic | PHP block: anonymous class extending `Livewire\Component`, properties, methods |
| **Bottom** | View | Blade/HTML: layout, directives, `wire:*` bindings |

The closing `?>` of the PHP block is the boundary. Everything above is component logic; everything below is the template.

**Minimal example:**

```php
<?php

use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public function save(): void
    {
        // Persist $this->name, flash message, etc.
    }
}; ?>

<div>
    <input type="text" wire:model="name" />
    <button wire:click="save">Save</button>
</div>
```

- **Top:** Public properties are component state; methods are actions (e.g. called by `wire:click="save"`).
- **Bottom:** `wire:model` and `wire:click` bind the view to that state and those actions.

So in one file you get: **controller (top) + view (bottom)**. Models and other classes stay in `app/`; the component only uses them.

---

## 2. The component class (top)

### 2.1 Extending Component and traits

```php
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
```

- Extend `Livewire\Volt\Component` (or `Livewire\Component`).
- Use Livewire traits as needed (e.g. `WithPagination` for paginated lists).

### 2.2 State (public properties)

```php
public string $search = '';
public string $filterCountryIso = '';
```

- Public properties are **reactive state**: when they change (e.g. from `wire:model`), Livewire re-renders the view as needed.
- Type hints are optional but recommended.

### 2.3 Passing data to the view: `with()`

Data that isn’t stored as component state (e.g. a paginated query result) is returned from `with()`:

```php
public function with(): array
{
    $query = Admin1::query()->orderBy('name');
    // ... apply filters from $this->search, $this->filterCountryIso ...

    return [
        'admin1s' => $query->paginate(20),
        'importedCountries' => $countryNames,
    ];
}
```

- Keys in the returned array become variables in the Blade template (e.g. `$admin1s`, `$importedCountries`).
- `with()` runs on every request that renders the component, so it’s the right place for derived/list data.

### 2.4 Actions (public methods)

Methods are the “controller actions” the view can call:

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

---

## 3. The Blade view (bottom)

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

- Components live in `resources/views/components/` or are registered in the app; Volt doesn’t change how they work.

---

## 4. Putting it together: request flow

1. **Initial load:** Livewire renders the component. The class is instantiated, `with()` runs, and the Blade template is rendered with the returned data and component state.
2. **User interaction (e.g. types in search):** `wire:model` updates `$search`; if you use `updatedSearch()`, it runs (e.g. `resetPage()`). Livewire may re-run `with()` and re-render the view.
3. **User clicks “Update”:** `wire:click="update"` calls `update()`. The method runs (e.g. runs the seeder, flashes a message). Livewire re-renders the view so flash messages and new data appear.

The important idea: **state lives in the component (top); the view (bottom) only displays it and triggers actions.** No separate controller file is needed.

---

## 5. File location and routing

- **Path:** `resources/views/livewire/{area}/{feature}/{name}.blade.php`  
  Example: `resources/views/livewire/admin/geonames/admin1/index.blade.php`
- **Route:** Usually one route that returns a Livewire component by name, e.g. `Livewire::volt('admin.geonames.admin1.index')` or a route that renders a layout and that layout includes the component. Component name is derived from the path (directory + file name without extension).

So: **one Volt file = one full-screen (or self-contained) component.** Keep logic in the top and template in the bottom; if the file gets too large, consider splitting into a class-based Livewire component (separate PHP class + Blade view) or extracting sub-components.

---

## 6. Quick reference

| Need | Where (Volt file) | How |
|------|-------------------|-----|
| Reactive state | Top: `public $prop` | Use `wire:model="prop"` or set in methods |
| Data for list/table | Top: `with()` return | Use `$key` in Blade |
| Button/link action | Top: `public function action()` | `wire:click="action"` or `wire:submit="action"` |
| Run when property changes | Top: `updatedPropertyName()` | Automatic after property update |
| Show in view | Bottom: `{{ $var }}`, `@if`, `@foreach` | Normal Blade |
| Loading state | Bottom: `wire:loading`, `wire:target="action"` | Livewire directives |

---

## 7. Further reading

- [Livewire Volt (official)](https://livewire.laravel.com/docs/volt) — functional API, `@volt`, and more options.
- [Livewire Volt Architecture](../architecture/livewire-volt.md) — why this project uses Volt and how it fits the stack.
