<?php
namespace App\Modules\Core\AI\Values;

/**
 * Rounded cent costs for a single LLM call.
 */
final readonly class CallCost
{
    public function __construct(
        public ?int $inputCents,
        public ?int $cachedInputCents,
        public ?int $outputCents,
        public ?int $totalCents,
    ) {}
}
