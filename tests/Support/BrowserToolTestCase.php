<?php

namespace Tests\Support;

use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Tools\BrowserTool;
use Tests\TestCase;

/**
 * Test case for {@see BrowserTool} unit tests.
 *
 * Injects synthetic execution context keys expected by the tool before each execute().
 */
abstract class BrowserToolTestCase extends TestCase
{
    use AssertsToolBehavior;

    public const BROWSER_TOOL_TEST_EMPLOYEE_ID = 701;

    public const BROWSER_TOOL_TEST_USER_ID = 703;

    public const BROWSER_TOOL_TEST_COMPANY_ID = 702;

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function mergeToolExecutionArguments(array $arguments): array
    {
        return array_merge([
            '_employee_id' => self::BROWSER_TOOL_TEST_EMPLOYEE_ID,
            '_acting_for_user_id' => self::BROWSER_TOOL_TEST_USER_ID,
            '_company_id' => self::BROWSER_TOOL_TEST_COMPANY_ID,
        ], $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function executeBrowserTool(array $arguments): ToolResult
    {
        return $this->tool->execute($this->mergeToolExecutionArguments($arguments));
    }
}
