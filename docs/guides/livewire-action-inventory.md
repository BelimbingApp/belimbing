# Find Livewire actions and test references

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
