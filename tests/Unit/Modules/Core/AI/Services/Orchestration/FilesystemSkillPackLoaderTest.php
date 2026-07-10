<?php

use App\Modules\Core\AI\Services\Orchestration\FilesystemSkillPackLoader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    File::deleteDirectory(base_path('extensions/custom/skill-loader-test'));
    File::deleteDirectory(base_path('app/Modules/Core/AI/.agents'));

    File::ensureDirectoryExists(base_path('extensions/custom/skill-loader-test/.agents/skills/licensee-flow'));
    File::put(
        base_path('extensions/custom/skill-loader-test/.agents/skills/licensee-flow/SKILL.md'),
        "---\nname: licensee-flow\ndescription: Use this for extension-owned work.\n---\n\n# Licensee Flow\n\nUse this for extension-owned work.",
    );

    File::ensureDirectoryExists(base_path('extensions/custom/skill-loader-test/billing/.agents/skills/invoice-triage'));
    File::put(
        base_path('extensions/custom/skill-loader-test/billing/.agents/skills/invoice-triage/SKILL.md'),
        "---\nname: invoice-triage\ndescription: Triage extension module invoices.\n---\n\n# Invoice Triage\n",
    );

    File::ensureDirectoryExists(base_path('app/Modules/Core/AI/.agents/skills/domain-demo'));
    File::put(
        base_path('app/Modules/Core/AI/.agents/skills/domain-demo/SKILL.md'),
        "---\nname: domain-demo\ndescription: Domain module skill demo.\n---\n\n# Domain Demo\n",
    );
});

afterEach(function (): void {
    File::deleteDirectory(base_path('extensions/custom/skill-loader-test'));
    File::deleteDirectory(base_path('app/Modules/Core/AI/.agents'));
});

it('loads core and extension skills from ownership scoped roots', function (): void {
    $manifests = (new FilesystemSkillPackLoader)->load();
    $ids = array_map(static fn ($manifest): string => $manifest->id, $manifests);

    expect($ids)->toContain('core.pr-review-thread-fix')
        ->and($ids)->toContain('extension.skill-loader-test.licensee-flow')
        ->and($ids)->toContain('extension.skill-loader-test.billing.invoice-triage')
        ->and($ids)->toContain('module.core.ai.domain-demo');

    $extension = collect($manifests)->firstWhere('id', 'extension.skill-loader-test.licensee-flow');
    $nested = collect($manifests)->firstWhere('id', 'extension.skill-loader-test.billing.invoice-triage');
    $domain = collect($manifests)->firstWhere('id', 'module.core.ai.domain-demo');

    expect($extension)->not->toBeNull()
        ->and($extension->owner)->toBe('extension:skill-loader-test')
        ->and($extension->description)->toBe('Use this for extension-owned work.')
        ->and($extension->references[0]->path)->toBe('extensions/custom/skill-loader-test/.agents/skills/licensee-flow/SKILL.md')
        ->and($nested->owner)->toBe('extension:skill-loader-test/billing')
        ->and($domain->owner)->toBe('module:core.ai');
});
