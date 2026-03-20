# FEAT-MODULE-FEATURE

Intent: add a permissioned CRUD feature to a module — route, authz, Livewire component, Blade view, and tests as a single vertical.

## When To Use

- Adding a new feature page (index, create, show) to an existing or new module.
- Feature requires route + authz middleware + Livewire component + Blade view.
- Any task that would previously have touched routes, authz, CRUD, UI, or tests together.

## Do Not Use When

- Schema-only work with no user-facing page (use `FEAT-MODULE-SCHEMA`).
- Inline field editing on an existing show page (use `FEAT-LW-INLINE-EDIT`).
- Workflow engine integration (use `FEAT-WORKFLOW-CONSUMER`, then this for the pages).
- Pure infrastructure changes to auto-discovery (use `FEAT-DISCOVERY`).

## Minimal File Pack

- `app/Modules/Business/IT/Routes/web.php`
- `app/Modules/Business/IT/Config/authz.php`
- `app/Modules/Business/IT/Config/menu.php`
- `app/Modules/Business/IT/Livewire/Tickets/Index.php`
- `resources/core/views/livewire/it/tickets/index.blade.php`

## Reference Shape

### 1. Surface area (routes, menu, authz config)

- **Routes**: auto-discovered from `Routes/web.php`. Group under `auth` middleware, add `authz:<capability>` per route.
- **Menu**: auto-discovered from `Config/menu.php`. Each item needs `id`, `label`, `route`, `permission`, `parent`, `position`.
- **Authz**: auto-discovered from `Config/authz.php`. Capability key grammar: `<domain>.<resource>.<action>`.
- **ServiceProvider**: auto-discovered from `ServiceProvider.php`. Usually empty for pure modules.
- Run authz seeder after any `Config/authz.php` change: `php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"`.

```php
// Routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('domain/resources', Index::class)
        ->middleware('authz:domain.resource.list')
        ->name('domain.resources.index');
    Route::get('domain/resources/create', Create::class)
        ->middleware('authz:domain.resource.create')
        ->name('domain.resources.create');
    Route::get('domain/resources/{resource}', Show::class)
        ->middleware('authz:domain.resource.view')
        ->name('domain.resources.show');
});

// Config/authz.php
return [
    'capabilities' => [
        'domain.resource.create',
        'domain.resource.view',
        'domain.resource.list',
    ],
];

// Config/menu.php
return [
    'items' => [
        ['id' => 'domain', 'label' => 'Domain', 'icon' => 'heroicon-o-...', 'parent' => 'business', 'position' => 100],
        ['id' => 'domain.resources', 'label' => 'Resources', 'icon' => 'heroicon-o-...', 'route' => 'domain.resources.index', 'permission' => 'domain.resource.list', 'parent' => 'domain', 'position' => 100],
    ],
];
```

### 2. Livewire components

- **Index**: use `ResetsPaginationOnSearch` + `WithPagination`. Query with `->when($this->search, ...)`. Paginate with `->paginate(25)`.
- **Create**: validate, persist, flash success, redirect to show page.
- **Show**: load model with relations in `mount()`. Pass computed data to view in `render()`.
- For searchable index pages, prefer extending `SearchablePaginatedList` or `TableSearchablePaginatedList` when the pattern fits.

```php
// Index.php
class Index extends Component
{
    use ResetsPaginationOnSearch, WithPagination;

    public string $search = '';

    public function render(): View
    {
        return view('livewire.module.entities.index', [
            'entities' => Entity::query()
                ->when($this->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
                ->latest()
                ->paginate(25),
        ]);
    }
}
```

### 3. Authorization enforcement

- **Route-level**: `authz:<capability>` middleware (covers page access).
- **Action-level**: for mutations inside Livewire methods, use `AuthorizationService::authorize()` or `ChecksCapabilityAuthorization` trait.
- Build actor: `Actor::forUser(auth()->user())`.
- Server-side authorization is mandatory even when UI hides the action.
- Unknown capabilities are denied by the policy pipeline.

```php
public function delete(int $id): void
{
    $actor = Actor::forUser(auth()->user());
    app(AuthorizationService::class)->authorize($actor, 'domain.resource.delete');

    Entity::query()->findOrFail($id)->delete();
}
```

### 4. Blade views

- Use `x-ui.*` components: `x-ui.page-header`, `x-ui.search-input`, `x-ui.button`, `x-ui.card`, `x-ui.badge`, `x-ui.alert`.
- Use semantic tokens only: `bg-surface-card`, `text-ink`, `text-muted`, `text-accent`, `border-border-default`.
- Use semantic spacing: `p-card-inner`, `py-table-cell-y`, `px-table-cell-x`.
- Wrap all user-facing strings with `__()`.
- Use `wire:key` on list items. Use `wire:model.live.debounce.300ms` for search.
- Use `wire:loading` + skeletons over spinners.

```blade
<x-ui.page-header :title="__('Resources')">
    <x-slot name="actions">
        <x-ui.button variant="primary" as="a" href="{{ route('domain.resources.create') }}" wire:navigate>
            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
            {{ __('Create') }}
        </x-ui.button>
    </x-slot>
</x-ui.page-header>

<x-ui.card>
    <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" />
    {{-- table with wire:key on rows --}}
</x-ui.card>
```

### 5. Tests

- Use `createAdminUser()` and `setupAuthzRoles()` in `beforeEach`.
- Cover: authorized access, unauthorized denial (403), search filtering, pagination reset, create validation, mutation side-effects.
- Prefer behavior assertions over shallow render checks.

```php
beforeEach(function (): void {
    setupAuthzRoles();
});

test('authorized user can access index', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)->assertOk();
});

test('unauthorized user is denied', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('domain.resources.index'))->assertForbidden();
});
```

## Required Invariants

- Routes, menu, and authz config live in module-local `Routes/` and `Config/`.
- Capability keys follow `<domain>.<resource>.<action>` grammar and stay stable once introduced.
- Server-side authz is mandatory; UI visibility is a UX enhancement only.
- Search updates reset pagination.
- Validation happens before any write operation.
- Blade views use semantic tokens and `x-ui.*` components, never raw primitives.
- All user-facing strings use `__()` translation helpers.
- Blade views go in `resources/core/views/livewire/{module}/{entities}/`, not inside the module directory.

## Test Checklist

- Authorized actor can access each route.
- Unauthorized actor receives 403.
- Menu item visibility follows capability.
- Route name and menu route stay aligned.
- List renders, search filters, pagination resets.
- Create validates and persists.
- Mutations enforce authorization.

## Common Pitfalls

- Creating routes but forgetting matching menu or capability entries.
- Relying on UI-only guards without server-side checks.
- Forgetting to re-seed authz data after config changes.
- Duplicating shared pagination/search logic instead of using `ResetsPaginationOnSearch`.
- Hardcoding English strings or raw Tailwind primitives in Blade.
- Using `<input>` or `<button>` where `x-ui.input` or `x-ui.button` exists.
- Assuming manual provider registration is required; ProviderRegistry discovers module providers.
