<?php

use App\Modules\Core\AI\Services\ChatTurnRunner;

it('defaults interactive Lara turns to structured repository tools before escalation tools', function (): void {
    expect(ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES)->toBe([
        'search',
        'read',
        'edit',
        'browser',
        'bash',
    ]);
});
