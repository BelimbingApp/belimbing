<?php

namespace Tests\Support;

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Tools\ToolResult;

trait AssertsToolBehavior
{
    /**
     * Merge synthetic arguments before tool execution (e.g. browser tool execution context).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function mergeToolExecutionArguments(array $arguments): array
    {
        return $arguments;
    }

    protected function assertToolMetadata(
        Tool $tool,
        string $expectedName,
        string $expectedCapability,
        array $expectedPropertyKeys = [],
        ?array $expectedRequired = null,
    ): void {
        expect($tool->name())->toBe($expectedName);
        expect($tool->description())->not->toBeEmpty();
        expect($tool->requiredCapability())->toBe($expectedCapability);

        $schema = $tool->parametersSchema();

        expect($schema['type'])->toBe('object');

        foreach ($expectedPropertyKeys as $key) {
            expect($schema['properties'])->toHaveKey($key);
        }

        if ($expectedRequired !== null) {
            expect($schema['required'] ?? [])->toBe($expectedRequired);
        }
    }

    protected function assertToolError(array $arguments, string ...$expectedFragments): string
    {
        $result = (string) $this->tool->execute($this->mergeToolExecutionArguments($arguments));

        expect($result)->toContain('Error');

        foreach ($expectedFragments as $fragment) {
            expect($result)->toContain($fragment);
        }

        return $result;
    }

    protected function assertRejectsMissingAndEmptyStringArgument(
        string $field,
        array $baseArguments = [],
        string ...$expectedFragments
    ): void {
        $this->assertToolError($baseArguments, ...$expectedFragments);
        $this->assertToolError([...$baseArguments, $field => ''], ...$expectedFragments);
    }

    protected function decodeToolExecution(array $arguments): array
    {
        return $this->decodeToolResult((string) $this->tool->execute($this->mergeToolExecutionArguments($arguments)));
    }

    protected function assertToolExecutionStatus(array $arguments, string $expectedStatus): array
    {
        $data = $this->decodeToolExecution($arguments);

        expect($data['status'])->toBe($expectedStatus);

        return $data;
    }

    protected function decodeToolResult(ToolResult|string $result): array
    {
        $data = json_decode((string) $result, true);

        expect($data)->toBeArray();

        return $data;
    }
}
