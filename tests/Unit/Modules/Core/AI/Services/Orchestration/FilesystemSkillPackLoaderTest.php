<?php

use App\Modules\Core\AI\Services\Orchestration\FilesystemSkillPackLoader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    File::deleteDirectory(base_path('extensions/custom/skill-loader-test'));
    File::ensureDirectoryExists(base_path('extensions/custom/skill-loader-test/.agents/skills/licensee-flow'));
    File::put(
        base_path('extensions/custom/skill-loader-test/.agents/skills/licensee-flow/SKILL.md'),
        "# Licensee Flow\n\nUse this for extension-owned work.",
    );
});

afterEach(function (): void {
    File::deleteDirectory(base_path('extensions/custom/skill-loader-test'));
});

it('loads core and extension skills from ownership scoped roots', function (): void {
    $manifests = (new FilesystemSkillPackLoader)->load();
    $ids = array_map(static fn ($manifest): string => $manifest->id, $manifests);

    expect($ids)->toContain('core.pr-review-thread-fix')
        ->and($ids)->toContain('extension.skill-loader-test.licensee-flow');

    $extension = collect($manifests)->firstWhere('id', 'extension.skill-loader-test.licensee-flow');

    expect($extension)->not->toBeNull()
        ->and($extension->owner)->toBe('extension:skill-loader-test')
        ->and($extension->references[0]->path)->toBe('extensions/custom/skill-loader-test/.agents/skills/licensee-flow/SKILL.md');
});
