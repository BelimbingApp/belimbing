<?php

namespace App\Base\Foundation\Contracts;

interface SemanticActionRecorder
{
    /**
     * Record a product-level action that explains user intent.
     *
     * @param  array{name?: string, id?: int|string, identifier?: string|null}  $subject
     * @param  array<string, mixed>  $context
     */
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
    ): void;
}
