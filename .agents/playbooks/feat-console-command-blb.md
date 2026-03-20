# FEAT-CONSOLE-BLB

Intent: add BLB console commands and Laravel command overrides with consistent naming and behavior contracts.

## When To Use

- Creating a new BLB framework command.
- Extending or overriding Laravel migration command behavior for BLB needs.
- Adding operational command flows tied to module conventions.

## Do Not Use When

- Command is an app-local script not intended as framework capability.
- Existing command can be configured without adding a new command surface.

## Minimal File Pack

- `app/Base/Workflow/Console/Commands/WorkflowCreateCommand.php`
- `app/Modules/Core/User/Console/Commands/CreateUserCommand.php`

## Reference Shape

- New BLB command names use `blb:` prefix (`blb:workflow:create`, `blb:user:create`).
- Laravel command overrides intentionally keep original names (example `migrate`).
- Service provider binds or extends command classes in `register()`.

## Required Invariants

- Use `#[AsCommand(name: '...')]` with matching signature.
- `handle()` returns `int` and uses success/failure constants where applicable.
- Keep command help text clear and action-oriented.
- Prefer explicit options over hidden implicit behavior.

## Implementation Skeleton

```php
#[AsCommand(name: 'blb:module:task')]
class ModuleTaskCommand extends Command
{
    protected $signature = 'blb:module:task {--option=}';

    public function handle(): int
    {
        // command logic

        return Command::SUCCESS;
    }
}
```

## Test Checklist

- Command is discoverable in `php artisan list`.
- Signature options and arguments parse as expected.
- Failure paths return proper exit code.
- Overridden Laravel command preserves baseline behavior plus BLB additions.

## Common Pitfalls

- Missing `blb:` prefix on new framework commands.
- Overriding Laravel commands without documenting divergence.
- Returning mixed/implicit values instead of explicit exit codes.
