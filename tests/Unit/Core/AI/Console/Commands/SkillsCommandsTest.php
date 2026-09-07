<?php

use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

// Fixture roots are test-owned by name — never point these at a directory
// that could hold real skills, since setup and teardown delete them wholesale.
const SKILL_CMD_TEST_EXTENSION = 'app/Extensions/SkillCmdTest';
const SKILL_CMD_TEST_DOMAIN = 'app/Domains/SkillCmdTest';
const SKILL_CMD_TEST_BODY = "# Probe Pack\n\nA body long enough to clear the minimum-length check on its own.\n";

function skillCmdTestWritePack(string $relativeDirectory, string $contents): void
{
    File::ensureDirectoryExists(base_path($relativeDirectory));
    File::put(base_path($relativeDirectory.'/SKILL.md'), $contents);
}

/**
 * @return array{packs: list<array<string, string>>, shadowed: list<array<string, string>>}
 */
function skillCmdTestJson(): array
{
    expect(Artisan::call('blb:ai:skills:list', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every listed row whose id comes from this file's fixture roots.
 *
 * The composed tree ships real skill packs, so a bare row count would be a
 * control that cannot fail — it would drift with the tree, not the fixture.
 *
 * @return list<array<string, string>>
 */
function skillCmdTestFixtureRows(): array
{
    return array_values(array_filter(
        skillCmdTestJson()['packs'],
        static fn (array $row): bool => str_contains($row['id'], 'skill-cmd-test'),
    ));
}

/**
 * @return array<string, string>
 */
function skillCmdTestRow(string $id): array
{
    $rows = array_values(array_filter(
        skillCmdTestJson()['packs'],
        static fn (array $row): bool => $row['id'] === $id,
    ));

    expect($rows)->toHaveCount(1);

    return $rows[0];
}

function skillCmdTestReset(): void
{
    File::deleteDirectory(base_path(SKILL_CMD_TEST_EXTENSION));
    File::deleteDirectory(base_path(SKILL_CMD_TEST_DOMAIN));
}

beforeEach(function (): void {
    skillCmdTestReset();

    skillCmdTestWritePack(
        SKILL_CMD_TEST_EXTENSION.'/.agents/skills/licensee-flow',
        "---\nname: Licensee Flow\ndescription: Use this for extension-owned work.\n---\n\n# Licensee Flow\n\nUse this for licensee-only escalation paths and nothing else.\n",
    );

    skillCmdTestWritePack(
        SKILL_CMD_TEST_DOMAIN.'/Demo/.agents/skills/domain-demo',
        "---\nname: Domain Demo\ndescription: Domain module skill demo.\n---\n\n# Domain Demo\n\nA domain-owned skill used only by the skills command tests.\n",
    );
});

afterEach(function (): void {
    skillCmdTestReset();
});

describe('blb:ai:skills:list', function (): void {
    it('lists one row per pack across ownership scoped roots', function (): void {
        $rows = skillCmdTestFixtureRows();

        expect($rows)->toHaveCount(2);

        [$extension, $domain] = $rows;

        expect($extension['id'])->toBe('extension.skill-cmd-test.licensee-flow')
            ->and($extension['owner'])->toBe('extension:skill-cmd-test')
            ->and($extension['name'])->toBe('Licensee Flow')
            ->and($extension['status'])->toBe('ready')
            ->and($extension['path'])->toBe(SKILL_CMD_TEST_EXTENSION.'/.agents/skills/licensee-flow/SKILL.md')
            ->and($domain['id'])->toBe('module.skill-cmd-test.demo.domain-demo')
            ->and($domain['owner'])->toBe('module:skill-cmd-test.demo')
            ->and($domain['path'])->toBe(SKILL_CMD_TEST_DOMAIN.'/Demo/.agents/skills/domain-demo/SKILL.md');
    });

    it('prints every JSON row in the table, and says how many it printed', function (): void {
        $packs = skillCmdTestJson()['packs'];

        // A command that discovered nothing would print an empty table and
        // still exit 0, so the count is the assertion that has to hold.
        expect(count($packs))->toBeGreaterThan(2);

        expect(Artisan::call('blb:ai:skills:list'))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain(count($packs).' skill pack(s) listed');

        foreach ($packs as $row) {
            expect($output)->toContain($row['id']);
        }
    });

    it('omits packs owned by a disabled domain', function (): void {
        DomainState::disable('SkillCmdTest');

        $rows = skillCmdTestFixtureRows();
        $ids = array_column($rows, 'id');

        expect($rows)->toHaveCount(1)
            ->and($ids)->toContain('extension.skill-cmd-test.licensee-flow');

        expect($ids)->not->toContain('module.skill-cmd-test.demo.domain-demo');
    });

    // The loader falls back to a slug title and a 200-character cut of the body
    // when frontmatter is absent, so the manifest of a malformed pack is
    // indistinguishable from a well-formed one. Same directory, same body;
    // the frontmatter block is the only variable between the two reads.
    it('separates a declared pack from one that only looks fine via the fallback', function (): void {
        $directory = SKILL_CMD_TEST_EXTENSION.'/.agents/skills/probe-pack';

        skillCmdTestWritePack($directory, "---\nname: Probe Pack\ndescription: Declared up front.\n---\n\n".SKILL_CMD_TEST_BODY);
        $declared = skillCmdTestRow('extension.skill-cmd-test.probe-pack');

        skillCmdTestWritePack($directory, SKILL_CMD_TEST_BODY);
        $fallback = skillCmdTestRow('extension.skill-cmd-test.probe-pack');

        // Every other field the manifest exposes is identical for the two trees...
        expect($fallback['name'])->toBe($declared['name'])
            ->and($fallback['status'])->toBe($declared['status'])
            ->and($fallback['owner'])->toBe($declared['owner'])
            ->and($fallback['path'])->toBe($declared['path']);

        // ...so the checks column is the only thing that can tell them apart.
        expect($declared['checks'])->toBe('ok')
            ->and($fallback['checks'])->toBe('name,description');
    });

    it('names the root that won and the root that was dropped for a duplicate id', function (): void {
        $kept = SKILL_CMD_TEST_EXTENSION.'/.agents/skills/billing.invoice';
        $dropped = SKILL_CMD_TEST_EXTENSION.'/billing/.agents/skills/invoice';
        $frontmatter = "---\nname: Invoice Triage\ndescription: Triage extension module invoices.\n---\n\n";

        skillCmdTestWritePack($kept, $frontmatter.SKILL_CMD_TEST_BODY);
        skillCmdTestWritePack($dropped, $frontmatter.SKILL_CMD_TEST_BODY);

        $json = skillCmdTestJson();
        $duplicates = array_values(array_filter(
            $json['packs'],
            static fn (array $row): bool => $row['id'] === 'extension.skill-cmd-test.billing.invoice',
        ));

        expect($duplicates)->toHaveCount(1)
            ->and($json['shadowed'])->toHaveCount(1)
            ->and($json['shadowed'][0]['id'])->toBe('extension.skill-cmd-test.billing.invoice')
            ->and($json['shadowed'][0]['kept'])->toBe($kept.'/SKILL.md')
            ->and($json['shadowed'][0]['dropped'])->toBe($dropped.'/SKILL.md');

        expect(Artisan::call('blb:ai:skills:list'))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('1 shadowed skill pack id(s)')
            ->and($output)->toContain('kept '.$kept.'/SKILL.md')
            ->and($output)->toContain('dropped '.$dropped.'/SKILL.md');
    });
});

describe('blb:ai:skills:verify', function (): void {
    it('exits 0 and lists the checks for a well formed pack', function (): void {
        $exit = Artisan::call('blb:ai:skills:verify', ['pack' => 'extension.skill-cmd-test.licensee-flow']);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('all 4 checks passed for extension.skill-cmd-test.licensee-flow');

        expect($output)->not->toContain('FAIL');
    });

    // Single variable: the same pack, the same frontmatter, the body shortened
    // below the minimum. Nothing else about the tree changes between the runs.
    it('exits 1 when the only change is a body under the minimum length', function (): void {
        $directory = SKILL_CMD_TEST_EXTENSION.'/.agents/skills/licensee-flow';
        $frontmatter = "---\nname: Licensee Flow\ndescription: Use this for extension-owned work.\n---\n\n";

        skillCmdTestWritePack($directory, $frontmatter.SKILL_CMD_TEST_BODY);
        expect(Artisan::call('blb:ai:skills:verify', ['pack' => 'extension.skill-cmd-test.licensee-flow']))->toBe(0);

        skillCmdTestWritePack($directory, $frontmatter.'Too short.');
        $exit = Artisan::call('blb:ai:skills:verify', ['pack' => 'extension.skill-cmd-test.licensee-flow']);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('1 of 4 checks failed')
            ->and($output)->toContain('FAIL  body is at least 40 characters');
    });

    it('exits 1 naming the missing name and description when frontmatter is absent', function (): void {
        skillCmdTestWritePack(SKILL_CMD_TEST_EXTENSION.'/.agents/skills/licensee-flow', SKILL_CMD_TEST_BODY);

        $exit = Artisan::call('blb:ai:skills:verify', ['pack' => 'extension.skill-cmd-test.licensee-flow']);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('2 of 4 checks failed')
            ->and($output)->toContain('FAIL  frontmatter declares a name')
            ->and($output)->toContain('FAIL  frontmatter declares a description');
    });

    it('exits 1 naming an unknown pack id', function (): void {
        $exit = Artisan::call('blb:ai:skills:verify', ['pack' => 'core.no-such-pack']);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('unknown skill pack core.no-such-pack');
    });
});
