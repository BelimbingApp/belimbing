<?php

return [
    /*
     * Headless CLI templates for repo-capable AI work. Schedules choose a
     * provider/model pair; {prompt} and {model} are shell-escaped by the
     * executor before substitution.
     */
    'providers' => [
        'anthropic' => [
            'label' => 'Claude Code',
            'command' => 'claude --print {prompt} --output-format json --model {model} --permission-mode bypassPermissions',
            'timeout_seconds' => 3600,
        ],
        'openai' => [
            'label' => 'OpenAI Codex CLI',
            'command' => 'codex exec --full-auto --model {model} {prompt}',
            'timeout_seconds' => 3600,
        ],
    ],

    'fallback_provider' => 'anthropic',
    'fallback_model' => 'claude-sonnet-5',

    'attribution_preamble' => 'You are running unattended as {attribution}. '
        .'Attribute generated work to "{attribution}" where the target workflow records model identity. ',
];
