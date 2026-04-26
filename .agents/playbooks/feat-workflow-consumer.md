# FEAT-WORKFLOW-CONSUMER

Intent: integrate a business module model with the BLB workflow engine for status-based lifecycle management.

## When To Use

- Model needs status transitions, history timeline, and transition guards.
- Module requires workflow seeder (statuses, transitions, kanban columns).
- Show page needs transition buttons, timeline display, and notification config.

## Do Not Use When

- Status is a simple enum column with no transition rules or history.
- Model lifecycle is fully CRUD without state-machine semantics.

## Minimal File Pack

- `app/Base/Workflow/Concerns/HasWorkflowStatus.php`
- `app/Base/Workflow/DTO/TransitionContext.php`
- `app/Modules/Operation/IT/Database/Seeders/TicketWorkflowSeeder.php`
- `app/Modules/Operation/IT/Livewire/Tickets/Show.php`

## Reference Shape

### Model integration

- Use `HasWorkflowStatus` trait on the Eloquent model.
- Implement abstract `flow(): string` returning a unique flow identifier (e.g., `'it_ticket'`).
- Model must have a `string status` column with a default matching the initial status code.
- The trait provides: `currentStatusConfig()`, `availableTransitions()`, `transitionTo()`, `statusTimeline()`.

### Workflow seeder

- Create `Database/Seeders/{Entity}WorkflowSeeder.php` that seeds four tables:
  - `Workflow::updateOrCreate()` — registry entry with `code`, `label`, `module`, `model_class`.
  - `StatusConfig::updateOrCreate()` — one row per status with `code`, `label`, `position`, `kanban_code`.
  - `StatusTransition::updateOrCreate()` — edges with `from_code`, `to_code`, `label`, `capability` (nullable).
  - `KanbanColumn::updateOrCreate()` — board columns with `code`, `label`, `position`.
- Register seeder in migration via `RegistersSeeders` trait: `$this->registerSeeder(WorkflowSeeder::class)`.
- Use `self::query()->updateOrCreate()` (not magic static calls).

### Create page

- Set initial `status` to the flow's starting status code directly on the model.
- Record initial `StatusHistory` entry with `actor_id`, `comment_tag`, and `transitioned_at`.

### Show page (transitions)

- Build `TransitionContext` with `Actor::forUser($user)` and optional `comment`.
- Call `$model->transitionTo($toCode, $context)` — returns `TransitionResult`.
- Check `$result->success` — flash success or `$result->reason` on failure.
- Pass `$model->availableTransitions()` and `$model->statusTimeline()` to view.

### Notifications (optional per status)

- Add `notifications` JSON on `StatusConfig` rows: `{"on_enter": ["reporter","assignee"], "channels": ["database"]}`.
- The `SendTransitionNotification` listener reads this config automatically.
- Recipients are resolved from model relations matching the `on_enter` keys, deduplicated, with actor excluded.

### Authz for transitions

- Guarded transitions set `capability` on `StatusTransition` (e.g., `'workflow.it_ticket.assign'`).
- The `TransitionValidator` checks the actor's capabilities against the transition's `capability` field.
- Add transition capabilities to `Config/authz.php` and re-seed.

## Required Invariants

- Flow identifier is unique across all modules.
- Workflow seeder is idempotent (uses `updateOrCreate`).
- Transitions go through `WorkflowEngine` — never update `status` directly except on initial creation.
- Initial `StatusHistory` is created on model creation (not via the engine).
- Migration `down()` calls `unregisterSeeder()` before dropping the table.

## Implementation Skeleton

```php
// Model
class Entity extends Model
{
    use HasFactory, HasWorkflowStatus;

    protected $table = 'module_entities';

    public function flow(): string
    {
        return 'module_entity';
    }
}

// WorkflowSeeder
class EntityWorkflowSeeder extends Seeder
{
    private const FLOW = 'module_entity';

    public function run(): void
    {
        Workflow::query()->updateOrCreate(
            ['code' => self::FLOW],
            ['label' => 'Entity', 'module' => 'module_entity',
             'model_class' => Entity::class, 'is_active' => true],
        );

        // Seed statuses, transitions, kanban columns...
    }
}

// Show component — transition action
public function transitionTo(string $toCode): void
{
    $context = new TransitionContext(
        actor: Actor::forUser(Auth::user()),
        comment: $this->transitionComment ?: null,
    );

    $result = $this->entity->transitionTo($toCode, $context);

    if ($result->success) {
        $this->transitionComment = '';
        $this->entity->refresh();
        Session::flash('success', __('Transitioned successfully.'));
    } else {
        Session::flash('error', $result->reason ?? __('Transition failed.'));
    }
}
```

## Dev Seeder Pattern

- Extend `DevSeeder` with `$dependencies` for topological ordering.
- Run the workflow seeder first: `(new EntityWorkflowSeeder)->run()`.
- Create sample entities at various lifecycle stages.
- Advance through statuses using `WorkflowEngine::transition()` in a helper method.

## Test Checklist

- Workflow seeder runs idempotently.
- Model can be transitioned through valid paths.
- Invalid transitions return failure result.
- Guarded transitions deny unauthorized actors.
- Timeline returns ordered history.
- Notification recipients are correct and exclude actor.

## Common Pitfalls

- Updating `status` directly on the model instead of using `transitionTo()`.
- Forgetting the initial `StatusHistory` record on creation.
- Using non-unique flow identifiers across modules.
- Missing `capability` in `Config/authz.php` for guarded transitions.
- Forgetting `$this->entity->refresh()` after successful transition in Livewire.
