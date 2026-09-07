# Find Livewire actions and test references

Platform quality checks `tests/ci/livewire-actions-baselines/platform.json` with
no `--domain` argument, in the platform-only checkout. Keep optional Domains and
Extensions unmounted when measuring this baseline: an unfiltered local scan
includes installed components too. The committed platform baseline uses count
mode; strict names remain opt-in. To lower the baseline after improvements, run
`APP_ENV=testing php artisan blb:livewire-actions --write-baseline=tests/ci/livewire-actions-baselines/platform.json`
and include the reviewed snapshot in the PR.

Add `--explain` to `--check-baseline=<path>` to print newly unreferenced
module-owned `Component::method` identities when the count increases.
`--write-baseline=<path>` stores an `actions` list alongside the count, ordered
by component and method. Older count-only files retain their pass/fail behavior;
explanation reports that their missing action list prevents identity comparison.
The count remains the gate, so exchanging an old action for a new one at the same
count does not fail. Lexical references are search leads, not proof of coverage.

Opt into set equality with `--check-baseline=<path> --strict-names`, or put
`"strict": true` in a baseline that includes `actions`. Any set change then
fails, including equal-count substitutions and removal-only improvements that
need a refreshed snapshot. New identities are printed without requiring
`--explain`. Strict mode refuses count-only baselines; use `--write-baseline`
first. Write with `--strict-names` as well to persist the opt-in in the new file.
Writing without it produces the default count-mode snapshot. Domain CI continues
to use count mode unless its committed baseline explicitly opts in.

Run `php artisan blb:livewire-actions` from the platform checkout. Use
`--domain=People` to select an installed, enabled Domain by its directory
name; use `--json` for machine-readable output. An unknown or disabled
Domain is an error. An enabled Domain with no components returns an empty list.

The table lists component class, public method, origin (module or shared), and
whether test source mentions that method. JSON also includes the matching
`test_files`, with repository-relative paths. Rows and file lists are sorted
for repeatable comparisons.

The command uses existing component discovery across Base, Core, enabled Domains
and Extensions. It reflects classes without constructing components or invoking
actions, constructors, render methods, or lifecycle hooks. Normal application
bootstrap still runs. Existing component-loading failures remain subject to the
discovery service's diagnostics.

Static methods, PHP magic methods, Livewire's base methods, render, lifecycle hooks
(including trait hooks), and attributed computed properties are excluded.
Inherited and trait-provided callable methods remain visible. `module_owned`
distinguishes methods defined under the component's own Module from shared
helpers; it does not decide whether an action needs authorization.

## Interpret a test reference

`referenced_in_tests` means a whole identifier appears in a PHP file under
the platform's `tests/` or an enabled Module's `Tests/` directory.
This is a lexical search: strings, comments, helper definitions and similarly
named methods on unrelated components all count. It is **not coverage**, proof
of a Livewire call, or proof of a denial test. Inspect `test_files`, then
prove the relevant behavior with a failing-first test or a guard mutation.

For comparison with a module-specific hand inventory, select that component and
`module_owned: true`. People PR153's Settings inventory contains
`createReferenceEntry`, `dryRunSampleImport`, and `setTab`.
The command reports all three and their test references. Shared pagination or
notification helpers stay separately visible rather than being mistaken for
additional module-owned business actions.

## CI ratchet (module-owned debt)

Domain CI fails when a Domain's count of **module-owned** actions with no
lexical test reference rises above the committed baseline in
`tests/ci/livewire-actions-baselines/{domain-id}.json`.

```bash
php artisan blb:livewire-actions --domain=People \
  --check-baseline=tests/ci/livewire-actions-baselines/people.json
```

When you cover existing debt, lower `module_owned_unreferenced` in the same PR:

```bash
php artisan blb:livewire-actions --domain=People \
  --write-baseline=tests/ci/livewire-actions-baselines/people.json
```

To lock in improvements across the platform and every pinned Domain without a
hand edit, dispatch `refresh-livewire-action-baselines`. It measures the
platform before Domains mount, then each pin after composition, applies each
snapshot through `scripts/ci/refresh-livewire-action-baselines.py` (never raises
a count), and opens one `bot-maintenance` PR.

The check still uses lexical references (not coverage). Raising a baseline is a
deliberate debt increase and should be rare.
