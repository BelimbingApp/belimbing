<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

/**
 * Base class for multi-action AI tools (deep tools).
 *
 * Seals `handle()` to provide action dispatch: extracts and validates the
 * 'action' parameter against `actions()`, then routes to `handleAction()`.
 * Concrete tools declare their supported actions and implement the dispatch.
 *
 * The 'action' parameter is automatically injected into `parametersSchema()`
 * as a required string enum — tools do not need to declare it manually.
 */
abstract class AbstractActionTool extends AbstractTool
{
    /**
     * List of supported action names.
     *
     * @return list<string>
     */
    abstract protected function actions(): array;

    /**
     * Handle a specific action after validation.
     *
     * @param  string  $action  The validated action name
     * @param  array<string, mixed>  $arguments  Full arguments (including 'action')
     */
    abstract protected function handleAction(string $action, array $arguments): ToolResult;

    /**
     * Dispatch to the appropriate action handler.
     *
     * Sealed — concrete tools implement `handleAction()` instead.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    final protected function handle(array $arguments): ToolResult
    {
        $action = $this->requireEnum($arguments, 'action', $this->actions());

        return $this->handleAction($action, $arguments);
    }

    /**
     * Build schema with the 'action' enum auto-injected.
     *
     * Merges the action parameter into whatever the tool's `schema()` provides.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array
    {
        $base = parent::parametersSchema();

        $actionProperty = [
            'type' => 'string',
            'enum' => $this->actions(),
            'description' => 'The action to perform.',
        ];

        // Inject 'action' as the first property
        $properties = ['action' => $actionProperty] + ($base['properties'] ?? []);

        $required = $base['required'] ?? [];
        if (! in_array('action', $required, true)) {
            array_unshift($required, 'action');
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }
}
