<?php

use App\Modules\Core\AI\Tools\SystemInfoTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
use Tests\Support\AssertsToolBehavior;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new SystemInfoTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'system_info',
            'ai.tool_system_info.execute',
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

    it('returns modules as array', function () {
        $data = $this->decodeToolExecution(['section' => 'modules']);

        expect($data['modules'])->toBeArray();
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
        $result = $this->tool->execute([]);

        expect($result)->toContain("\n");
    });
});
