<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\SemanticActionRecorder;

class NullSemanticActionRecorder implements SemanticActionRecorder
{
    public function record(
        string $event,
        string $summary,
        ?string $source = null,
        array $subject = [],
        ?string $surface = null,
        ?string $uiElement = null,
        array $context = [],
        string $result = 'succeeded',
        bool $retain = true,
    ): void {
        // Safe default for installations that do not load the Audit module.
    }
}
