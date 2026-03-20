# FEAT-LW-INLINE-EDIT

Intent: implement inline editable Livewire show pages with field-level validation, safe persistence, and proper UI tokens.

## When To Use

- Detail pages where individual fields are edited without full-form submit.
- Save-on-change controls for model attributes.
- Existing model already has clear field rule set.

## Do Not Use When

- Mutation requires multi-step transaction across many models.
- Inline save would hide critical user confirmation requirements.
- Building the show page from scratch (use `FEAT-MODULE-FEATURE` for the full vertical first).

## Minimal File Pack

- `app/Base/Foundation/Livewire/Concerns/SavesValidatedFields.php`
- `app/Modules/Core/User/Livewire/Users/Show.php`

## Reference Shape

### Livewire component

- `saveField(string $field, mixed $value)` delegates to `saveValidatedField()`.
- Optional `beforeSave` closure handles side-effects (example: reset email verification).
- Domain-specific save methods (`saveCompany`, `saveStatus`) handle constrained fields.
- Authorize mutations server-side using `Actor::forUser()` + `AuthorizationService::authorize()`.

### Blade view

- Use `x-ui.input`, `x-ui.select`, `x-ui.textarea` for editable fields — not raw `<input>`.
- Use semantic tokens: `text-ink`, `text-muted`, `bg-surface-card`, `border-border-default`.
- Use semantic spacing: `p-card-inner`, `px-input-x`, `py-input-y`.
- Set explicit `id` on form controls for accessibility.
- Wrap labels and placeholders with `__()`.

## Required Invariants

- Validate single field before persistence.
- Skip unknown field names safely.
- Keep side-effects explicit near save path.
- Flash messages only for user-visible milestone actions, not every keystroke.
- Server-side authorization even when UI hides edit controls.

## Implementation Skeleton

```php
public function saveField(string $field, mixed $value): void
{
    $rules = [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255'],
    ];

    $this->saveValidatedField(
        $this->model,
        $field,
        $value,
        $rules,
        function ($model, string $validatedField): void {
            if ($validatedField === 'email' && $model->isDirty('email')) {
                $model->email_verified_at = null;
            }
        }
    );
}
```

## Test Checklist

- Valid inline field update persists.
- Invalid value is rejected.
- Side-effect behavior occurs when expected.
- Unauthorized inline update is blocked where required.

## Common Pitfalls

- Duplicating ad hoc validation and save logic across pages.
- Applying full-form validation for single-field updates.
- Missing model reload where relationship-backed UI depends on fresh state.
- Using raw `<input>` instead of `x-ui.input` for editable fields.
- Hardcoding spacing instead of semantic tokens.
