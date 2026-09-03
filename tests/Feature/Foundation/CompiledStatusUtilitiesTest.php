<?php

use Illuminate\Support\Facades\File;

/**
 * Every status-colour utility a Blade view uses must resolve to a rule in
 * the compiled stylesheet (belimbing#538).
 *
 * `tokens.css` defines the `status-warning` / `status-danger` / `status-success`
 * / `status-info` families. A view that writes `text-warning`, `bg-danger-surface`
 * or `text-status-error` from muscle memory compiles to **no rule at all** —
 * no fallback, no build warning, no console error, and no failing test,
 * because `assertSee` matches raw HTML and nothing asserts computed style.
 * Nine such occurrences across seven files, in three different dead
 * spellings, shipped through review that way.
 *
 * Source-level checks cannot catch this: a token can exist in `tokens.css`
 * and still be emitted only in an opacity variant (`…-subtle/40`), so a bare
 * use compiles to nothing while every grep says it is fine. The only
 * authority is the emitted CSS, so that is what this compares against.
 *
 * Scope is deliberately the status-colour families, where the vocabulary is
 * small and every name is meant to be a real token; a check over every
 * Tailwind class in every view would drown in dynamic class strings.
 */
const STATUS_UTILITY_PATTERN = '/(?<![\w-])((?:[a-z-]+:)*(?:bg|text|border|ring|divide|outline|fill|stroke|from|to|via)-(?:status-)?(?:warning|danger|error|success|info)(?:-[a-z]+)*(?:\/\d+)?)(?![\w-])/';

/** @return list<array{class: string, file: string, line: int}> */
function statusUtilitiesUsedInViews(): array
{
    $uses = [];

    foreach (File::allFiles(resource_path('core/views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $index => $line) {
            if (preg_match_all(STATUS_UTILITY_PATTERN, $line, $matches) > 0) {
                foreach (array_unique($matches[1]) as $class) {
                    $uses[] = ['class' => $class, 'file' => $file->getRelativePathname(), 'line' => $index + 1];
                }
            }
        }
    }

    return $uses;
}

function compiledStylesheet(): ?string
{
    $candidates = glob(public_path('build/assets/*.css')) ?: [];

    if ($candidates === []) {
        return null;
    }

    usort($candidates, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return (string) file_get_contents($candidates[0]);
}

function selectorExistsFor(string $class, string $css): bool
{
    // Tailwind escapes `:` and `/` in selectors; a variant class ends in a
    // pseudo-class (`:hover`) and a bare one in `{` or `,`.
    $escaped = preg_quote(str_replace([':', '/'], ['\\:', '\\/'], $class), '/');

    return preg_match('/\.'.$escaped.'(?![\w-])/', $css) === 1;
}

test('every status-colour utility used by a view resolves to a rule in the compiled stylesheet', function (): void {
    $css = compiledStylesheet();

    if ($css === null) {
        // Mechanism skip: this compares against emitted CSS and needs a
        // build (`bun run build`); CI builds assets before running tests.
        $this->markTestSkipped('mechanism: no compiled stylesheet under public/build/assets; run `bun run build`.');
    }

    $uses = statusUtilitiesUsedInViews();
    expect($uses)->not->toBeEmpty();

    $dead = [];

    foreach ($uses as $use) {
        if (! selectorExistsFor($use['class'], $css)) {
            $dead[] = "{$use['file']}:{$use['line']} uses `{$use['class']}`, which compiles to no rule";
        }
    }

    expect($dead)->toBe([], "Status-colour utilities that render nothing (see tokens.css for the status-* families):\n".implode("\n", $dead));
});
