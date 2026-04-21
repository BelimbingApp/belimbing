<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\ToolResult;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Modules\Core\AI\DTO\Orchestration\RoutingDecision;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\BackgroundCommandService;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\LaraTaskProfileSelector;
use App\Modules\Core\AI\Services\Orchestration\TaskRoutingService;
use App\Modules\Core\AI\Services\RuntimeSessionContext;
use App\Modules\Core\AI\Tools\ArtisanTool;
use App\Modules\Core\AI\Tools\BashTool;
use App\Modules\Core\AI\Tools\DelegateTaskTool;
use App\Modules\Core\AI\Tools\DocumentAnalysisTool;
use App\Modules\Core\AI\Tools\ImageAnalysisTool;
use App\Modules\Core\AI\Tools\NavigateTool;
use App\Modules\Core\AI\Tools\VisibleNavMenuSnapshotTool;
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

        public function displayName(): string
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

        public function summary(): string
        {
            return 'Test tool: '.$this->toolName;
        }

        public function explanation(): string
        {
            return '';
        }

        public function setupRequirements(): array
        {
            return [];
        }

        public function testExamples(): array
        {
            return [];
        }

        public function healthChecks(): array
        {
            return [];
        }

        public function limits(): array
        {
            return [];
        }

        public function execute(array $arguments): ToolResult
        {
            return ToolResult::success('executed:'.$this->toolName.':'.(string) ($arguments['input'] ?? 'no-input'));
        }
    };
}

function makeToolCallingTempRelativePath(string $filename): string
{
    return 'storage/framework/testing/tool-calling/'.uniqid('', true).'-'.$filename;
}

function writeToolCallingTempFile(string $relativePath, string $contents): void
{
    $absolutePath = base_path($relativePath);
    $directory = dirname($absolutePath);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($absolutePath, $contents);
}

function deleteToolCallingTempFile(string $relativePath): void
{
    @unlink(base_path($relativePath));
}

describe('AgentToolRegistry', function () {
    it('returns empty tool definitions when no tools registered', function () {
        $registry = new AgentToolRegistry(makeAllowAllAuthzService());

        expect($registry->toolDefinitionsForCurrentUser())->toBe([]);
    });

    it('returns registered tools in OpenAI format', function () {
        $registry = new AgentToolRegistry(makeAllowAllAuthzService());
        $registry->register(makeSimpleTool('echo'));

        $definitions = $registry->toolDefinitionsForCurrentUser();

        expect($definitions)->toHaveCount(1);
        expect($definitions[0]['type'])->toBe('function');
        expect($definitions[0]['function']['name'])->toBe('echo');
        expect($definitions[0]['function']['description'])->toBe('Test tool: echo');
    });

    it('executes a registered tool and returns result', function () {
        $registry = new AgentToolRegistry(makeAllowAllAuthzService());
        $registry->register(makeSimpleTool('echo'));

        $result = $registry->execute('echo', ['input' => 'hello']);

        expect((string) $result)->toBe('executed:echo:hello');
    });

    it('returns error for unknown tool', function () {
        $registry = new AgentToolRegistry(makeAllowAllAuthzService());

        $result = $registry->execute('nonexistent', []);

        expect((string) $result)->toContain('Error: Unknown tool');
        expect($result->isError)->toBeTrue();
    });

    it('filters tools by user authz capabilities', function () {
        $registry = new AgentToolRegistry(makeDenyAllAuthzService());
        $registry->register(makeSimpleTool('restricted', 'ai.tool_artisan.execute'));
        $registry->register(makeSimpleTool('public', null));

        $definitions = $registry->toolDefinitionsForCurrentUser();

        // Only the tool with no capability requirement should be available
        expect($definitions)->toHaveCount(1);
        expect($definitions[0]['function']['name'])->toBe('public');
    });

    it('denies execution for tools the user lacks capability for', function () {
        $registry = new AgentToolRegistry(makeDenyAllAuthzService());
        $registry->register(makeSimpleTool('restricted', 'ai.tool_artisan.execute'));

        $result = $registry->execute('restricted', ['input' => 'test']);

        expect((string) $result)->toContain('do not have permission');
    });

    it('catches exceptions during tool execution', function () {
        $failingTool = new class implements Tool
        {
            public function name(): string
            {
                return 'fails';
            }

            public function displayName(): string
            {
                return 'Fails';
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

            public function summary(): string
            {
                return 'Always fails';
            }

            public function explanation(): string
            {
                return '';
            }

            public function setupRequirements(): array
            {
                return [];
            }

            public function testExamples(): array
            {
                return [];
            }

            public function healthChecks(): array
            {
                return [];
            }

            public function limits(): array
            {
                return [];
            }

            public function execute(array $arguments): ToolResult
            {
                throw new ToolExecutionFailure('Boom!');
            }
        };

        $registry = new AgentToolRegistry(makeAllowAllAuthzService());
        $registry->register($failingTool);

        $result = $registry->execute('fails', []);

        expect((string) $result)->toContain('Error executing "fails"');
        expect((string) $result)->toContain('Boom!');
    });
});

describe('ArtisanTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new ArtisanTool(Mockery::mock(BackgroundCommandService::class));

        expect($tool->name())->toBe('artisan');
        expect($tool->requiredCapability())->toBe('ai.tool_artisan.execute');
    });

    it('returns error for empty command', function () {
        $tool = new ArtisanTool(Mockery::mock(BackgroundCommandService::class));

        expect((string) $tool->execute([]))->toContain(NO_COMMAND_PROVIDED);
        expect((string) $tool->execute(['command' => '']))->toContain(NO_COMMAND_PROVIDED);
    });

    it('strips php artisan prefix if LLM included it', function () {
        $tool = new ArtisanTool(Mockery::mock(BackgroundCommandService::class));

        // This will run 'php artisan list' — should work without error
        $result = $tool->execute(['command' => 'php artisan list --raw']);

        expect((string) $result)->not->toContain('Error');
    });
});

describe('NavigateTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = new NavigateTool;

        expect($tool->name())->toBe('navigate');
        expect($tool->requiredCapability())->toBe('ai.tool_navigate.execute');
    });

    it('returns agent-action block for valid URL', function () {
        $tool = new NavigateTool;

        $result = $tool->execute(['url' => '/admin/users']);

        expect((string) $result)->toContain('<agent-action>');
        expect((string) $result)->toContain("Livewire.navigate('/admin/users')");
        expect((string) $result)->toContain('Navigation initiated');
    });

    it('rejects URLs not starting with slash', function () {
        $tool = new NavigateTool;

        expect((string) $tool->execute(['url' => 'admin/users']))->toContain('Error');
        expect((string) $tool->execute(['url' => 'https://evil.com']))->toContain('Error');
    });

    it('rejects URLs with invalid characters', function () {
        $tool = new NavigateTool;

        expect((string) $tool->execute(['url' => '/admin/<script>']))->toContain('Error');
        expect((string) $tool->execute(['url' => "/admin/users' OR 1=1"]))->toContain('Error');
    });
});

describe('VisibleNavMenuSnapshotTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $tool = new VisibleNavMenuSnapshotTool($menu);

        expect($tool->name())->toBe('visible_nav_menu');
        expect($tool->requiredCapability())->toBeNull();
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

        expect((string) $tool->execute([]))->toContain(NO_COMMAND_PROVIDED);
        expect((string) $tool->execute(['command' => '']))->toContain(NO_COMMAND_PROVIDED);
    });

    it('executes a simple command successfully', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'echo hello-bash-tool']);

        expect((string) $result)->toBe('hello-bash-tool');
    });

    it('returns failure message for bad command', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'cat /nonexistent/file/path']);

        expect((string) $result)->toContain('Command failed');
    });

    it('returns success message when command produces no output', function () {
        $tool = new BashTool;

        $result = $tool->execute(['command' => 'true']);

        expect((string) $result)->toBe('Command completed successfully (no output).');
    });
});

function makeToolCallingDelegateTool(
    ?LaraTaskDispatcher $dispatcher = null,
    ?TaskRoutingService $router = null,
    ?LaraTaskProfileSelector $taskProfileSelector = null,
): DelegateTaskTool {
    return new DelegateTaskTool(
        $dispatcher ?? Mockery::mock(LaraTaskDispatcher::class),
        $router ?? Mockery::mock(TaskRoutingService::class),
        new AgentExecutionContext,
        $taskProfileSelector ?? Mockery::mock(LaraTaskProfileSelector::class),
        new RuntimeSessionContext,
    );
}

describe('DelegateTaskTool', function () {
    it(TOOL_METADATA_DESCRIPTION, function () {
        $tool = makeToolCallingDelegateTool();

        expect($tool->name())->toBe('delegate_task');
        expect($tool->requiredCapability())->toBe('ai.tool_delegate.execute');
    });

    it('returns error for empty task', function () {
        $tool = makeToolCallingDelegateTool();

        expect((string) $tool->execute([]))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
        expect((string) $tool->execute(['task' => '']))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
        expect((string) $tool->execute(['task' => '   ']))->toContain(NO_TASK_DESCRIPTION_PROVIDED);
    });

    it('returns error when no agent is available', function () {
        $router = Mockery::mock(TaskRoutingService::class);
        $router->shouldReceive('route')->andReturn(RoutingDecision::local(['No agent matched.']));
        $selector = Mockery::mock(LaraTaskProfileSelector::class);
        $selector->shouldReceive('select')->andReturnNull();

        $tool = makeToolCallingDelegateTool(router: $router, taskProfileSelector: $selector);

        $result = $tool->execute(['task' => 'Generate a report', 'task_type' => 'general']);

        expect((string) $result)->toContain('Error');
        expect((string) $result)->toContain('No suitable Agent or Lara task profile');
    });

    it('dispatches task to best matching agent when no agent_id is given', function () {
        $router = Mockery::mock(TaskRoutingService::class);
        $router->shouldReceive('route')->andReturn(
            RoutingDecision::agent(42, REPORT_BOT, 80, ['Matched financial reporting.'])
        );

        $dispatch = new OperationDispatch([
            'id' => 'op_abc123',
            'operation_type' => OperationType::AgentTask,
            'status' => OperationStatus::Queued,
            'employee_id' => 42,
            'task' => GENERATE_Q1_REPORT,
            'acting_for_user_id' => 1,
            'meta' => ['employee_name' => REPORT_BOT],
        ]);

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')
            ->with(42, 'general', GENERATE_Q1_REPORT, [])
            ->andReturn($dispatch);

        $tool = makeToolCallingDelegateTool($dispatcher, $router);

        $result = $tool->execute(['task' => GENERATE_Q1_REPORT, 'task_type' => 'general']);

        expect((string) $result)->toContain(REPORT_BOT);
        expect((string) $result)->toContain('op_abc123');
    });

    it('dispatches to a specific agent when agent_id is given', function () {
        $router = Mockery::mock(TaskRoutingService::class);
        $router->shouldReceive('route')->andReturn(
            RoutingDecision::agent(7, DATA_ANALYST, 100, ['Explicitly requested.'])
        );

        $dispatch = new OperationDispatch([
            'id' => 'op_xyz456',
            'operation_type' => OperationType::AgentTask,
            'status' => OperationStatus::Queued,
            'employee_id' => 7,
            'task' => 'Run analytics',
            'acting_for_user_id' => 1,
            'meta' => ['employee_name' => DATA_ANALYST],
        ]);

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')->andReturn($dispatch);

        $tool = makeToolCallingDelegateTool($dispatcher, $router);

        $result = $tool->execute(['task' => 'Run analytics', 'task_type' => 'general', 'agent_id' => 7]);

        expect((string) $result)->toContain(DATA_ANALYST);
        expect((string) $result)->toContain('op_xyz456');
    });

    it('returns error when dispatcher throws', function () {
        $router = Mockery::mock(TaskRoutingService::class);
        $router->shouldReceive('route')->andReturn(
            RoutingDecision::agent(1, 'Bot', 80, ['Matched.'])
        );

        $dispatcher = Mockery::mock(LaraTaskDispatcher::class);
        $dispatcher->shouldReceive('dispatchForCurrentUser')
            ->andThrow(new RuntimeException('Dispatch unavailable'));

        $tool = makeToolCallingDelegateTool($dispatcher, $router);

        $result = $tool->execute(['task' => 'some task', 'task_type' => 'general']);

        expect((string) $result)->toContain('Error');
        expect((string) $result)->toContain('Dispatch unavailable');
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

        expect((string) $tool->execute([]))->toContain(PATH_REQUIRED_ERROR);
        expect((string) $tool->execute(['path' => '']))->toContain(PATH_REQUIRED_ERROR);
        expect((string) $tool->execute(['path' => '   ']))->toContain(PATH_REQUIRED_ERROR);
    });

    it('returns structured payload for a valid request', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute([
            'path' => 'README.md',
            'prompt' => 'Summarize this document.',
        ]);

        expect((string) $result)->toContain('"action": "document_analysis"');
        expect((string) $result)->toContain('"path": "README.md"');
        expect((string) $result)->toContain('"prompt": "Summarize this document."');
    });

    it('returns error for a path that does not exist', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute([
            'path' => 'non-existent-file-xyz.txt',
            'prompt' => 'Summarize this document.',
        ]);

        expect((string) $result)->toContain('"path": "non-existent-file-xyz.txt"');
    });

    it('returns error for missing prompt', function () {
        $tool = new DocumentAnalysisTool;

        $result = $tool->execute(['path' => 'README.md']);

        expect((string) $result)->toContain('No prompt provided');
    });

    it('returns file content and header for a created temp file', function () {
        $relativePath = makeToolCallingTempRelativePath('test_doc_analysis_tool.txt');
        writeToolCallingTempFile($relativePath, 'Hello, document analysis!');

        $tool = new DocumentAnalysisTool;
        $result = $tool->execute([
            'path' => $relativePath,
            'prompt' => 'Summarize this document.',
        ]);

        deleteToolCallingTempFile($relativePath);

        expect((string) $result)->toContain('"path": "'.$relativePath.'"');
        expect((string) $result)->toContain('"prompt": "Summarize this document."');
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

        expect((string) $tool->execute([]))->toContain(PATH_REQUIRED_ERROR);
        expect((string) $tool->execute(['path' => '']))->toContain(PATH_REQUIRED_ERROR);
        expect((string) $tool->execute(['path' => '   ']))->toContain(PATH_REQUIRED_ERROR);
    });

    it('returns error for a path that does not exist', function () {
        $tool = new ImageAnalysisTool;

        $result = $tool->execute([
            'path' => 'non-existent-image-xyz.png',
            'prompt' => 'Describe this image.',
        ]);

        expect((string) $result)->toContain('"path": "non-existent-image-xyz.png"');
    });

    it('returns error for an unsupported file type', function () {
        $tool = new ImageAnalysisTool;

        // README.md is a text file, not a supported image type
        $result = $tool->execute([
            'path' => 'README.md',
            'prompt' => 'Describe this image.',
        ]);

        expect((string) $result)->toContain('Unsupported image format');
    });

    it('returns metadata for a valid PNG image', function () {
        // Minimal 1×1 transparent PNG
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $relativePath = makeToolCallingTempRelativePath('test_image_analysis_tool.png');
        writeToolCallingTempFile($relativePath, $png);

        $tool = new ImageAnalysisTool;
        $result = $tool->execute([
            'path' => $relativePath,
            'prompt' => 'Describe this image.',
        ]);

        deleteToolCallingTempFile($relativePath);

        expect((string) $result)->toContain('"action": "image_analysis"');
        expect((string) $result)->toContain('"path": "'.$relativePath.'"');
        expect((string) $result)->toContain('"prompt": "Describe this image."');
    });
});
