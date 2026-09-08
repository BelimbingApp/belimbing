<?php

/**
 * #900: keep docs/architecture/tenancy.md's relative app/ and tests/ links
 * resolvable, and keep the async inventory section naming the inventory file
 * and the Schedule facade. Hermetic: filesystem only. Helpers are prefixed
 * tenancyDoc so they stay unique across the composed suite.
 */
function tenancyDocMarkdown(): string
{
    return file_get_contents(base_path('docs/architecture/tenancy.md'))
        ?: throw new RuntimeException('docs/architecture/tenancy.md is unreadable.');
}

/** @return list<string> Absolute paths under the repo for every relative app/ or tests/ markdown link. */
function tenancyDocRelativeCodeTargets(string $markdown): array
{
    preg_match_all('/\]\(([^)]+)\)/', $markdown, $matches);

    $targets = [];
    foreach ($matches[1] as $href) {
        if (! is_string($href) || str_starts_with($href, 'http') || str_starts_with($href, '#')) {
            continue;
        }
        if (! str_contains($href, '/app/') && ! str_contains($href, '/tests/') && ! str_starts_with($href, '../../app/') && ! str_starts_with($href, '../../tests/')) {
            continue;
        }
        $targets[] = realpath(base_path('docs/architecture/'.$href))
            ?: base_path('docs/architecture/'.ltrim($href, './'));
    }

    return array_values(array_unique($targets));
}

function tenancyDocAsyncSection(string $markdown): string
{
    if (! preg_match('/### Platform async entry-point inventory\n(.*?)(?=\n### |\z)/s', $markdown, $match)) {
        throw new RuntimeException('Platform async entry-point inventory section is missing from tenancy.md.');
    }

    return $match[1];
}

it('names the async entry-point inventory file in tenancy.md and the file exists', function (): void {
    $section = tenancyDocAsyncSection(tenancyDocMarkdown());

    expect($section)->toContain('PlatformAsyncEntryPointInventory.php')
        ->and($section)->toContain('../../app/Base/Tenancy/Support/PlatformAsyncEntryPointInventory.php')
        ->and(base_path('app/Base/Tenancy/Support/PlatformAsyncEntryPointInventory.php'))->toBeFile();
});

it('resolves every relative app/ and tests/ link in tenancy.md to an existing path', function (): void {
    $targets = tenancyDocRelativeCodeTargets(tenancyDocMarkdown());

    expect($targets)->not->toBeEmpty();
    foreach ($targets as $path) {
        expect($path)->toBeFile();
    }
});

it('names the Schedule facade in the async inventory section', function (): void {
    expect(tenancyDocAsyncSection(tenancyDocMarkdown()))
        ->toContain('Illuminate\\Support\\Facades\\Schedule');
});
