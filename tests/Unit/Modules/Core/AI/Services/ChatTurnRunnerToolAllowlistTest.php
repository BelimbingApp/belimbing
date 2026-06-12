<?php

use App\Modules\Core\AI\Services\ChatTurnRunner;

it('defaults interactive Lara turns to repository and active-page tools before shell escalation', function (): void {
    expect(ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES)->toBe([
        'search',
        'read',
        'edit',
        'active_page_snapshot',
        'bash',
    ]);
});
