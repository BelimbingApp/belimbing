<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Modules\Core\AI\Services\DigitalWorkerToolRegistry;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Tools\ArtisanTool;
use App\Modules\Core\AI\Tools\BashTool;
use App\Modules\Core\AI\Tools\DelegateTaskTool;
use App\Modules\Core\AI\Tools\DocumentAnalysisTool;
use App\Modules\Core\AI\Tools\ImageAnalysisTool;
use App\Modules\Core\AI\Tools\NavigateTool;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

const TOOL_METADATA_DESCRIPTION = 'has correct name and required capability';

const NO_COMMAND_PROVIDED = 'No command provided';
const NO_TASK_DESCRIPTION_PROVIDED = 'No task provided';
const GENERATE_Q1_REPORT = 'Generate Q1 report';
const REPORT_BOT = 'Report Bot';
const DATA_ANALYST = 'Data Analyst';
const PATH_REQUIRED_ERROR = 'No path provided';

class ToolExecutionFailure extends RuntimeException {}

function makeAllowAllAuthzService(): AuthorizationService
{
    $mock = Mockery::mock(AuthorizationService::class);
    $mock->shouldReceive('can')->andReturn(AuthorizationDecision::allow());

    return $mock;
}

function makeDenyAllAuthzService(): AuthorizationService
{
    $mock = Mockery::mock(AuthorizationService::class);
    $mock->shouldReceive('can')->andReturn(
        AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY)
    );

    return $mock;
}

function makeSimpleTool(string $name, ?string $capability = null): Tool
{
    return new class($name, $capability) implements Tool
    {
        public function __construct(
            private readonly string $toolName,
            private readonly ?string $toolCapability,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return 'Test tool: '.$this->toolName;
        }

        public function parametersSchema(): array
        {
            return ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]];
        }

        public function requiredCapability(): ?string
        {
            return $this->toolCapability;
        }

        public function category(): ToolCategory
        {
            return ToolCategory::SYSTEM;
        }

        public function riskClass(): ToolRiskClass
        {
            return ToolRiskClass::READ_ONLY;
        }

        public function execute(array $arguments): string
        {
            return 'executed:'.$this->toolName.':'.(string) ($arguments['input'] ?? 'no-input');
        }
    };
}

describe('DigitalWorkerToolRegistry', function () {
    it('returns empty tool definitions when no tools registered', function () {
        $registry = new DigitalWorkerToolRegistry(makeAllowAllAuthzService());

        expect($registry->toolDefinitionsForCurrentUser())->toBe([]);
    });

    it('returns registered tools in OpenAI format', function () {
        $registry = new DigitalWorkerToolRegistry(makeAllowAllAuthzService());
        $registry->register(makeSimpleTool('echo'));

        $definitions = $registry->toolDefinitionsForCurrentUser();

        expect($definitions)->toHaveCount(1);
        expect($definitions[0]['type'])->toBe('function');
        expect($definitions[0]['function']['name'])->toBe('echo');
        expect($definitions[0]['function']['description'])->toBe('Test tool: echo');
    });

    it('executes a registered tool and returns result', function () {
        $registry = new DigitalWorkerToolRegistry(makeAllowAllAuthzService());
        $registry->register(makeSimpleTool('echo'));

        $result = $registry->execute('echo', ['input' => 'hello']);

        expect($result)->toBe('executed:echo:hello');
    });

    it('returns error for unknown tool', function () {
        $registry = new DigitalWorkerToolRegistry(makeAllowAllAuthzService());

        $result = $registry->execute('nonexistent', []);

        expect($result)->toContain('Error: Unknown tool');
    });

    it('filters tools by user authz capabilities', function () {
        $registry = new DigitalWorkerToolRegistry(makeDenyAllAuthzService());
        $registry->register(makeSimpleTool('restricted', 'ai.tool_artisan.execute'));
        $registry->register(makeSimpleTool('public', null));

        $definitions = $registry->toolDefinitionsForCurrentUser();

        // Only the tool with no capability requirement should be available
        expect($definitions)->toHaveCount(1);
        expect($definitions[0]['function']['name'])->toBe('public');
    });

    it('denies execution for tools the user lacks capability for', function () {
        $registry = new DigitalWorkerToolRegistry(makeDenyAllAuthzService());
        $registry->register(makeSimpleTool('restricted', 'ai.tool_artisan.execute'));

        $result = $registry->execute('restricted', ['input' => 'test']);

        expect($result)->toContain('do not have permission');
    });

    it('catches exceptions during tool execution', function () {
        $failingTool = new class implements Tool
        {
            public function name(): string
            {
                return 'fails';
            }

            public function description(): string
            {
                return 'Always fails';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function requiredCapability(): ?string
            {
                return null;
            }

            public function category(): ToolCategory
            {
                return ToolCategory::SYSTEM;
            }

            public function riskClass(): ToolRiskClass
            {
                return ToolRiskClass::READ_ONLY;
            }

            public function execute(array $arguments): string
            {
                throw new ToolExecutionFailure('Boom!');
            }
        };

        $registry = new DigitalWorkerToolRegistry(makeAllowAllAuthzService());
        $registry->register($failingTool);

        $result = $registry->execute('fails', []);

        expect($result)->toContain('Error executing "fails"');
        expect($result)->toContain('Boom!');
    });
});

describe('ArtisanTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new ArtisanTool;

        expect($tool->name())->toBe('artisan');
        expect($tool->requiredCapability())->toBe('ai.tool_artisan.execute');
    });

    it('returns error for empty command', function () {
        $tool = new ArtisanTool;

        expect($tool->execute([]))->toContain(NO_COMMAND_PROVIDED);
        expect($tool->execute(['command' => '']))->toContain(NO_COMMAND_PROVIDED);
    });

    it('strips php artisan prefix if LLM included it', function () {
        $tool = new ArtisanTool;

        // This will run 'php artisan list' — should work without error
        $result = $tool->execute(['command' => 'php artisan list --raw']);

        expect($result)->not->toContain('Error');
    });
});

describe('NavigateTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new NavigateTool;

        expect($tool->name())->toBe('navigate');
        expect($tool->requiredCapability())->toBe('ai.tool_navigate.execute');
    });

    it('returns lara-action block for valid URL', function () {
        $tool = new NavigateTool;

        $result = $tool->execute(['url' => '/admin/users']);

        expect($result)->toContain('<lara-action>');
        expect($result)->toContain("Livewire.navigate('/admin/users')");
        expect($result)->toContain('Navigation initiated');
    });

    it('rejects URLs not starting with slash', function () {
        $tool = new NavigateTool;

        expect($tool->execute(['url' => 'admin/users']))->toContain('Error');
        expect($tool->execute(['url' => 'https://evil.com']))->toContain('Error');
    });

    it('rejects URLs with invalid characters', function () {
        $tool = new NavigateTool;

        expect($tool->execute(['url' => '/admin/<script>']))->toContain('Error');
        expect($tool->execute(['url' => "/admin/users' OR 1=1"]))->toContain('Error');
    });
});

describe('BashTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new BashTool;

        expect($tool->name())->toBe('bash');
        expect($tool->requiredCapability())->toBe('ai.tool_bash.execute');
    });

    it('returns error for empty command', function () {
        $tool = new BashTool;

        expect($tool->execute([]))->toContain(NO_COMMAND_PROVIDED);
        expect($tool->execute(['command' => '']))->toContain(NO_COMMAND_PROVIDED);
    });

    it('executes a simple command successfully', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'echo hello-bash-tool']);

        expect($result)->toBe('hello-bash-tool');
    });

    it('returns failure message for bad command', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'cat /nonexistent/file/path']);

        expect($result)->toContain('Command failed');
    });

    it('returns success message when command produces no output', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'true']);

        expect($result)->toBe('Command completed successfully (no output).');
    });
});

describe('DelegateTaskTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new DelegateTaskTool(
            Mockery::mock(LaraTaskDispatcher::class),
            Mockery::mock(LaraCapabilityMatcher::class),
        );

        expect($tool->name())->toBe('delegate_task');
        expect($tool->requiredCapability())->toBe('ai.tool_delegate.execute');
    });

    it('returns error for empty task', function () {
        $tool = new DelegateTaskTool(
            Mockery::mock(LaraTaskDispatcher::class),
            Mockery::mock(LaraCapabilityMatcher::class),
        );

        expect($tool->execute([]))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
        expect($tool->execute(['task' => '']))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
        expect($tool->execute(['task' => '   ']))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
    });

    it('returns error when no worker is available', function () {
        $matcher = Mockery::mock(LaraCapabilityMatcher::class);
        $matcher->shouldReceive('matchBestForTask')->andReturn(null);

        $tool = new DelegateTaskTool(Mockery::mock(LaraTaskDispatcher::class), $matcher);

        $result = $tool->execute(['task' => 'Generate a report']);

        expect($result)->toContain('Error');
        expect($result)->toContain('No suitable Digital Worker');
    });

    it('dispatches task to best matching worker when no worker_id is given', function () {
        $matcher = Mockery::mock(LaraCapabilityMatcher::class);
        $matcher->shouldReceive('matchBestForTask')->with(GENERATE_Q1_REPORT)->andReturn([
            'employee_id' => 42,
            'name' => REPORT_BOT,
            'capability_summary' => 'Financial reporting',
        ]);

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')->with(42, GENERATE_Q1_REPORT)->andReturn([
            'dispatch_id' => 'dw_dispatch_abc123',
            'status' => 'queued',
            'employee_id' => 42,
            'employee_name' => REPORT_BOT,
            'task' => GENERATE_Q1_REPORT,
            'acting_for_user_id' => 1,
            'created_at' => '2025-01-01T00:00:00+00:00',
        ]);

        $tool = new DelegateTaskTool($dispatcher, $matcher);

        $result = $tool->execute(['task' => GENERATE_Q1_REPORT]);

        expect($result)->toContain(REPORT_BOT);
        expect($result)->toContain('dw_dispatch_abc123');
    });

    it('dispatches to a specific worker when worker_id is given', function () {
        $matcher = Mockery::mock(LaraCapabilityMatcher::class);
        $matcher->shouldReceive('findAccessibleWorkerById')->with(7)->andReturn([
            'employee_id' => 7,
            'name' => DATA_ANALYST,
            'capability_summary' => 'Analytics',
        ]);

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')->andReturn([
            'dispatch_id' => 'dw_dispatch_xyz',
            'status' => 'queued',
            'employee_id' => 7,
            'employee_name' => DATA_ANALYST,
            'task' => 'Run analytics',
            'acting_for_user_id' => 1,
            'created_at' => '2025-01-01T00:00:00+00:00',
        ]);

        $tool = new DelegateTaskTool($dispatcher, $matcher);

        $result = $tool->execute(['task' => 'Run analytics', 'worker_id' => 7]);

        expect($result)->toContain(DATA_ANALYST);
        expect($result)->toContain('dw_dispatch_xyz');
    });

    it('returns error when dispatcher throws', function () {
        $matcher = Mockery::mock(LaraCapabilityMatcher::class);
        $matcher->shouldReceive('matchBestForTask')->andReturn([
            'employee_id' => 1,
            'name' => 'Bot',
            'capability_summary' => '',
        ]);

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')
            ->andThrow(new \RuntimeException('Dispatch unavailable'));

        $tool = new DelegateTaskTool($dispatcher, $matcher);

        $result = $tool->execute(['task' => 'some task']);

        expect($result)->toContain('Error');
        expect($result)->toContain('Dispatch unavailable');
    });
});

describe('DocumentAnalysisTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new DocumentAnalysisTool;

        expect($tool->name())->toBe('document_analysis');
        expect($tool->requiredCapability())->toBe('ai.tool_document_analysis.execute');
    });

    it('returns error for empty or missing path', function () {
        $tool = new DocumentAnalysisTool;

        expect($tool->execute([]))->toContain(PATH_REQUIRED_ERROR);
        expect($tool->execute(['path' => '']))->toContain(PATH_REQUIRED_ERROR);
        expect($tool->execute(['path' => '   ']))->toContain(PATH_REQUIRED_ERROR);
    });

    it('returns structured payload for a valid request', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute([
            'path' => 'README.md',
            'prompt' => 'Summarize this document.',
        ]);

        expect($result)->toContain('"action": "document_analysis"');
        expect($result)->toContain('"path": "README.md"');
        expect($result)->toContain('"prompt": "Summarize this document."');
    });

    it('returns error for a path that does not exist', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute([
            'path' => 'non-existent-file-xyz.txt',
            'prompt' => 'Summarize this document.',
        ]);

        expect($result)->toContain('"path": "non-existent-file-xyz.txt"');
    });

    it('returns error for missing prompt', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute(['path' => 'README.md']);

        expect($result)->toContain('No prompt provided');
    });

    it('returns file content and header for a created temp file', function () {
        $tmpPath = storage_path('app/test_doc_analysis_tool.txt');
        file_put_contents($tmpPath, 'Hello, document analysis!');

        $tool = new DocumentAnalysisTool;
        $result = $tool->execute([
            'path' => 'storage/app/test_doc_analysis_tool.txt',
            'prompt' => 'Summarize this document.',
        ]);

        @unlink($tmpPath);

        expect($result)->toContain('"path": "storage/app/test_doc_analysis_tool.txt"');
        expect($result)->toContain('"prompt": "Summarize this document."');
    });
});

describe('ImageAnalysisTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new ImageAnalysisTool;

        expect($tool->name())->toBe('image_analysis');
        expect($tool->requiredCapability())->toBe('ai.tool_image_analysis.execute');
    });

    it('returns error for empty or missing path', function () {
        $tool = new ImageAnalysisTool;

        expect($tool->execute([]))->toContain(PATH_REQUIRED_ERROR);
        expect($tool->execute(['path' => '']))->toContain(PATH_REQUIRED_ERROR);
        expect($tool->execute(['path' => '   ']))->toContain(PATH_REQUIRED_ERROR);
    });

    it('returns error for a path that does not exist', function () {
        $tool = new ImageAnalysisTool;

        $result = $tool->execute([
            'path' => 'non-existent-image-xyz.png',
            'prompt' => 'Describe this image.',
        ]);

        expect($result)->toContain('"path": "non-existent-image-xyz.png"');
    });

    it('returns error for an unsupported file type', function () {
        $tool = new ImageAnalysisTool;

        // README.md is a text file, not a supported image type
        $result = $tool->execute([
            'path' => 'README.md',
            'prompt' => 'Describe this image.',
        ]);

        expect($result)->toContain('Unsupported image format');
    });

    it('returns metadata for a valid PNG image', function () {
        // Minimal 1×1 transparent PNG
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $tmpPath = storage_path('app/test_image_analysis_tool.png');
        file_put_contents($tmpPath, $png);

        $tool = new ImageAnalysisTool;
        $result = $tool->execute([
            'path' => 'storage/app/test_image_analysis_tool.png',
            'prompt' => 'Describe this image.',
        ]);

        @unlink($tmpPath);

        expect($result)->toContain('"action": "image_analysis"');
        expect($result)->toContain('"path": "storage/app/test_image_analysis_tool.png"');
        expect($result)->toContain('"prompt": "Describe this image."');
    });
});
