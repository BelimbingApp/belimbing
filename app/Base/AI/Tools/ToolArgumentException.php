<?php
namespace App\Base\AI\Tools;

/**
 * Thrown when a tool argument fails validation.
 *
 * Caught by AbstractTool::execute() and formatted as an error response.
 * Use this for input validation errors that should be reported to the LLM
 * without a stack trace.
 */
final class ToolArgumentException extends \InvalidArgumentException {}
