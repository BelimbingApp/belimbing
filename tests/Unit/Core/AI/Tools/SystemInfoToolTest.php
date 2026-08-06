<?php

use App\Core\AI\Tools\SystemInfoTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new SystemInfoTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'system_info',
            'admin.ai.tool.system-info.execute',
            ['section'],
            [],
        );

        expect($this->tool->parametersSchema()['properties']['section'])->toHaveKey('enum');
    });
});

describe('section selection', function () {
    it('returns all sections by default', function () {
        $data = $this->decodeToolExecution([]);

        expect($data)->toHaveKeys(['framework', 'modules', 'providers', 'health']);
    });

    it('returns only requested section', function () {
        $data = $this->decodeToolExecution(['section' => 'framework']);

        expect($data)->toHaveKey('framework')
            ->and($data)->not->toHaveKey('modules')
            ->and($data)->not->toHaveKey('providers')
            ->and($data)->not->toHaveKey('health');
    });

    it('falls back to all for invalid section', function () {
        $data = $this->decodeToolExecution(['section' => 'bogus']);

        expect($data)->toHaveKeys(['framework', 'modules', 'providers', 'health']);
    });

    it('returns framework section with expected keys', function () {
        $data = $this->decodeToolExecution(['section' => 'framework']);

        expect($data['framework'])->toHaveKeys([
            'laravel_version',
            'php_version',
            'php_sapi',
            'environment',
            'debug_mode',
            'timezone',
            'locale',
        ]);
    });

    it('returns health section with expected keys', function () {
        $data = $this->decodeToolExecution(['section' => 'health']);

        expect($data['health'])->toHaveKeys([
            'queue_connection',
            'cache_driver',
            'session_driver',
            'database',
            'storage_writable',
        ]);
    });

    it('reports database as connected', function () {
        $data = $this->decodeToolExecution(['section' => 'health']);

        expect($data['health']['database'])->toBe('connected');
    });

    it('returns active non-Base module identities across the four-root topology', function () {
        $data = $this->decodeToolExecution(['section' => 'modules']);

        // core/ai is a Core module that ships with the platform repo and is
        // always present. Domain and Extension modules live in private nested
        // repos that are not checked out in CI or a fresh platform-only clone,
        // so they are asserted conditionally.
        expect($data['modules'])
            ->toContain('core/ai')
            ->not->toContain('base/foundation');

        $conditionalModules = [
            'people/payroll' => app_path('Domains/People/Payroll'),
            'ham/auto-parts' => app_path('Extensions/Ham/AutoParts'),
        ];

        foreach ($conditionalModules as $moduleId => $root) {
            if (is_dir($root)) {
                expect($data['modules'])->toContain($moduleId);
            }
        }
    });

    it('reports an invalid module manifest inventory as unavailable', function () {
        $extension = app_path('Extensions/SystemInfoToolTest'.bin2hex(random_bytes(4)));
        $module = $extension.'/Probe';

        File::ensureDirectoryExists($module);
        File::put($module.'/composer.json', json_encode([
            'name' => 'test/system-info-topology-probe',
            'extra' => ['blb' => ['module' => 'core/ai']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $result = $this->tool->execute(['section' => 'modules']);

            expect($result->isError)->toBeTrue()
                ->and($result->errorPayload?->code)->toBe('module_discovery_unavailable')
                ->and($result->errorPayload?->hint)->toContain('Duplicate BLB module identity [core/ai]');
        } finally {
            File::deleteDirectory($extension);
        }
    });

    it('returns providers as array', function () {
        $data = $this->decodeToolExecution(['section' => 'providers']);

        expect($data['providers'])->toBeArray();
    });
});

describe('output format', function () {
    it('returns valid JSON', function () {
        expect($this->decodeToolExecution([]))->toBeArray();
    });

    it('returns pretty-printed JSON', function () {
        $result = (string) $this->tool->execute([]);

        expect($result)->toContain("\n");
    });
});
