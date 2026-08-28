<?php

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/**
 * Blade compiles directives inside plain HTML attributes but treats a *component*
 * tag's attribute value as a string literal handed to the ComponentAttributeBag.
 * `<x-ui.button wire:click="act(@js($id))">` therefore emits the literal text
 * `@js($id)` into the rendered attribute, and the handler silently does nothing
 * (#416).
 *
 * This asserts on compiled output rather than on component methods, because a
 * Livewire test that calls ->call('act', $id) invokes the method directly and
 * never parses the attribute — which is exactly why the defect shipped, and why
 * it had already been fixed once in isolation (UiReferenceTest) without a guard
 * that would catch the next occurrence.
 */
it('leaves no uncompiled @js/@json directive in any compiled Blade view', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(resource_path())->name('*.blade.php') as $file) {
        $compiled = Blade::compileString($file->getContents());

        if (preg_match_all('/@(?:js|json)\(/', $compiled, $matches)) {
            $offenders[] = sprintf(
                '%s (%d occurrence%s)',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                count($matches[0]),
                count($matches[0]) === 1 ? '' : 's',
            );
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These views emit a literal @js( / @json( into their rendered output —',
        'the directive sits in a component-tag attribute, where Blade does not',
        'compile it. Bind the attribute instead: :wire:click="\'act(\'.Js::from($id).\')\'".',
        '',
        ...$offenders,
    ]));
});
