<?php

use App\Base\AI\Services\AiRuntimeSettings;

return [
    'definitions' => [
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => [
            'type' => 'integer',
            'scopes' => ['global'],
            'default' => 100,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'integer', 'min:1', 'max:500'],
        ],
        AiRuntimeSettings::PDFTOTEXT_PATH_KEY => [
            'type' => 'string',
            'scopes' => ['global'],
            'default' => null,
            'nullable' => true,
            'encrypted' => false,
            'rules' => ['nullable', 'string', 'max:2048'],
        ],
    ],

    // Internal state and transitional keys written by Core AI.
    'runtime' => [
        'ai.lara.interactive_extra_tool_names',
        // Transitional claim until every installation has run the key-rename migration.
        'ai.llm.agentic.max_tool_iterations',
        'ai.openai_codex.models_discovery_client_version',
        'ai.providers.*',
        'ai.tools.*',
    ],
];
