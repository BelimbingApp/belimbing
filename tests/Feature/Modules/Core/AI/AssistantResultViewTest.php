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

    $stopNotePosition = strpos($html, 'You stopped this run before it finished.');
    $runIdPosition = strpos($html, 'title="run-stop-note-001"');

    expect($html)
        ->toContain('You stopped this run before it finished.')
        ->toContain('title="run-stop-note-001"')
        ->toContain('openai/gpt-5');

    expect($stopNotePosition)->not->toBeFalse()
        ->and($runIdPosition)->not->toBeFalse()
        ->and($stopNotePosition)->toBeLessThan($runIdPosition);
});

