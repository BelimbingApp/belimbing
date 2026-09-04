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
 * Twenty-five such occurrences across eight files, in three dead spellings
 * (one view alone held nine), shipped through review that way — a vocabulary
 * problem, not a handful of typos, which is what earns a permanent check.
 *
 * How that list was produced matters for trusting the next one: the issue's
 * original five files came from grepping three class names already known to
 * be bad, which finds what one thought to look for. The 25 came from
 * enumerating every status-colour utility the views use and checking each
 * against the emitted stylesheet — the method this test runs on every build.
 *
 * Source-level checks cannot catch this: a token can exist in `tokens.css`
 * and still be emitted only in an opacity variant (`…-subtle/40`), so a bare
 * use compiles to nothing while every grep says it is fine. The only
 * authority is the emitted CSS, so that is what this compares against.
 *
 * Scope is a deliberate trade, not a limitation: the status-colour families,
 * where the vocabulary is small and every name is meant to be a real token.
 * A check over every Tailwind class in every view would drown in dynamic
 * class strings and be switched off.
 */
const STATUS_UTILITY_PATTERN = '/(?<![\w-])((?:[a-z0-9-]+(?:\[[^\]]*\])?:)*(?:bg|text|border|ring|divide|outline|fill|stroke|from|to|via)-(?:status-)?(?:warning|danger|error|success|info)(?:-[a-z]+)*(?:\/\d+)?!?)(?![\w-])/';

/**
 * The scan root is stated, not defaulted: Tailwind's automatic source
 * detection reads `resources/core/views` and `app/**\/*.php`, and status
 * utilities are written as literal strings in PHP too (`StatusVariant`, the
 * mirror and deployment concerns), so both are walked. Nested domain repos
 * have their own `@source` lines and their own CI.
 *
 * @return list<array{class: string, file: string, line: int}>
 */
function statusUtilitiesUsedInViews(): array
{
    $uses = [];
    $files = [...File::allFiles(resource_path('core/views')), ...File::allFiles(app_path())];

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
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

    // Every emitted stylesheet, not the newest: a second entrypoint would
    // otherwise make its selectors look dead.
    return implode("\n", array_map(fn (string $path): string => (string) file_get_contents($path), $candidates));
}

function cssEscapedClass(string $class): string
{
    $escaped = (string) preg_replace_callback('/[^a-zA-Z0-9_-]/', fn (array $match): string => '\\'.$match[0], $class);

    return preg_match('/^\d/', $escaped) === 1
        ? '\\3'.$escaped[0].' '.substr($escaped, 1)
        : $escaped;
}

function selectorExistsFor(string $class, string $css): bool
{
    // CSS escapes more than Tailwind's common `:` and `/`: bracket variants
    // need escaped brackets, and a leading digit becomes a hex escape plus a
    // space (`2xl` becomes `\\32 xl`). Escape the whole class before turning it
    // into a regular expression so a live selector cannot be reported dead.
    $escaped = preg_quote(cssEscapedClass($class), '/');

    // The character after the name must not continue it: not a word
    // character, not a hyphen, and not a backslash — Tailwind escapes `/`, so
    // `.x-subtle\/40` would otherwise answer for a bare `x-subtle` that has
    // no rule of its own. That is the opacity-variant case the docblock
    // names, and it was reachable until this exclusion (review of #542).
    return preg_match('/\.'.$escaped.'(?![\w\\\\-])/', $css) === 1;
}

test('it recognizes CSS-escaped status utility selectors without accepting their dead spellings', function (): void {
    $css = '.\\32 xl\\:text-status-warning{color:ok}'
        .'.min-\\[600px\\]\\:text-status-danger{color:ok}'
        .'.supports-\\[display\\:grid\\]\\:text-status-info{color:ok}'
        .'.text-status-success\\!{color:ok}'
        .'.border-status-info-subtle\\/40{color:ok}';

    expect(selectorExistsFor('2xl:text-status-warning', $css))->toBeTrue()
        ->and(selectorExistsFor('min-[600px]:text-status-danger', $css))->toBeTrue()
        ->and(selectorExistsFor('supports-[display:grid]:text-status-info', $css))->toBeTrue()
        ->and(selectorExistsFor('text-status-success!', $css))->toBeTrue()
        ->and(selectorExistsFor('2xl:text-warning', $css))->toBeFalse()
        ->and(selectorExistsFor('border-status-info-subtle', $css))->toBeFalse();
});

test('every status-colour utility used by a view resolves to a rule in the compiled stylesheet', function (): void {
    $css = compiledStylesheet();

    if ($css === null) {
        // A skipped test is green, and this check exists to remove silent
        // non-detection — so a missing build is only tolerated where a
        // developer may genuinely not have built. In CI the build step runs
        // before the suite, and its absence is itself the defect: fail.
        // Presence of CI, not its value: a runner setting CI=false would be
        // cast to false by env() and silently return to skipping.
        if (getenv('CI') !== false) {
            $this->fail('No compiled stylesheet under public/build/assets in CI: the build step did not run before the tests, so nothing can be verified against emitted CSS.');
        }

        // Mechanism skip, local only: needs `bun run build`.
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
