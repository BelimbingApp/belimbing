<?php

use App\Modules\Core\AI\Services\ChatTurnRunner;

it('defines the minimal default interactive agent tool surface', function (): void {
    expect(ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES)->toBe([
        'bash',
        'browser',
    ]);
});
