<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;

/**
 * Base class for AI tools providing common boilerplate.
 *
 * Seals `execute()` to provide a uniform error-handling envelope, typed
 * argument extraction, and a schema builder API. Concrete tools implement
 * `handle()` with only their domain logic.
 *
 * Tools that do not fit this pattern can implement the `Tool` interface directly.
 */
abstract class AbstractTool implements Tool
{
    /**
     * Define the tool's parameter schema using the fluent builder.
     *
     * Override this to declare parameters. Return null if the tool takes no parameters.
     */
    protected function schema(): ?ToolSchemaBuilder
    {
        return null;
    }

    /**
     * Build the JSON Schema from the fluent builder.
     *
     * Tools may override this directly if they need raw schema control
     * (e.g., for oneOf or complex nested schemas).
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array
    {
        $builder = $this->schema();

        if ($builder === null) {
            return ['type' => 'object', 'properties' => new \stdClass];
        }

        return $builder->build();
    }

    /**
     * Execute the tool with uniform error handling.
     *
     * Wraps `handle()` in a try/catch to standardize error output.
     * Concrete tools should not override this; implement `handle()` instead.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    final public function execute(array $arguments): string
    {
        try {
            return $this->handle($arguments);
        } catch (ToolArgumentException $e) {
            return 'Error: '.$e->getMessage();
        }
    }

    /**
     * Tool-specific execution logic.
     *
     * Implement this instead of `execute()`. Throw `ToolArgumentException`
     * for input validation errors — they'll be caught and formatted by
     * `execute()`. Other exceptions propagate to the registry's error handler.
     *
     * @param  array<string, mixed>  $arguments  Parsed and validated arguments from LLM
     */
    abstract protected function handle(array $arguments): string;

    // ─── Typed argument extractors ──────────────────────────────────

    /**
     * Extract a required string argument, trimmed and validated non-empty.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     * @param  string|null  $label  Human-readable label for error messages (defaults to key)
     *
     * @throws ToolArgumentException If the argument is missing, not a string, or empty after trimming
     */
    protected function requireString(array $arguments, string $key, ?string $label = null): string
    {
        $label ??= $key;
        $value = $arguments[$key] ?? '';

        if (! is_string($value) || trim($value) === '') {
            throw new ToolArgumentException("No {$label} provided.");
        }

        return trim($value);
    }

    /**
     * Extract an optional string argument, returning null if missing or empty.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     */
    protected function optionalString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Extract a required integer argument, clamped to the given range.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     * @param  int|null  $min  Minimum value (inclusive)
     * @param  int|null  $max  Maximum value (inclusive)
     *
     * @throws ToolArgumentException If the argument is missing or not an integer
     */
    protected function requireInt(array $arguments, string $key, ?int $min = null, ?int $max = null): int
    {
        $value = $arguments[$key] ?? null;

        if (! is_int($value)) {
            throw new ToolArgumentException("\"{$key}\" is required and must be an integer.");
        }

        return $this->clampInt($value, $min, $max);
    }

    /**
     * Extract an optional integer argument with a default, clamped to the given range.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     * @param  int  $default  Default value if missing
     * @param  int|null  $min  Minimum value (inclusive)
     * @param  int|null  $max  Maximum value (inclusive)
     */
    protected function optionalInt(array $arguments, string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        $value = $arguments[$key] ?? null;

        if (! is_int($value)) {
            return $this->clampInt($default, $min, $max);
        }

        return $this->clampInt($value, $min, $max);
    }

    /**
     * Extract an optional boolean argument with a default.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     * @param  bool  $default  Default value if missing
     */
    protected function optionalBool(array $arguments, string $key, bool $default = false): bool
    {
        return (bool) ($arguments[$key] ?? $default);
    }

    /**
     * Extract a string argument validated against an enum of allowed values.
     *
     * @param  array<string, mixed>  $arguments  Raw arguments
     * @param  string  $key  Argument key
     * @param  list<string>  $allowed  Allowed values
     * @param  string|null  $default  Default value if missing (must be in allowed list or null)
     *
     * @throws ToolArgumentException If the value is not in the allowed list and no default is set
     */
    protected function requireEnum(array $arguments, string $key, array $allowed, ?string $default = null): string
    {
        $value = $arguments[$key] ?? $default;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            if ($default !== null) {
                return $default;
            }

            throw new ToolArgumentException(
                "\"{$key}\" must be one of: ".implode(', ', $allowed).'.'
            );
        }

        return $value;
    }

    /**
     * Clamp an integer value to the given range.
     */
    private function clampInt(int $value, ?int $min, ?int $max): int
    {
        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }
}
