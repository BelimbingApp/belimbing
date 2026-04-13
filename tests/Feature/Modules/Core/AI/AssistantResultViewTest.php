<?php

use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use Illuminate\Support\Facades\Blade;

it('renders the user stop note above the assistant response metadata', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ai.activity.assistant-result
    content="Partial answer"
    :timestamp="$timestamp"
    run-id="run-stop-note-001"
    provider="openai"
    model="gpt-5"
    :markdown="$markdown"
    stop-note="You stopped this run before it finished."
/>
BLADE,
        [
            'timestamp' => now(),
            'markdown' => app(ChatMarkdownRenderer::class),
        ]
    ));

    expect($html)
        ->toContain('You stopped this run before it finished.')
        ->toContain('run-stop…')
        ->toContain('openai/gpt-5');
});
