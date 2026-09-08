<?php

namespace App\Base\Workflow\Process\Definitions;

use InvalidArgumentException;

final readonly class ProcessDependency
{
    /**
     * @var non-empty-list<non-empty-string>
     */
    public array $acceptableOutcomes;

    /**
     * @param  list<mixed>  $acceptableOutcomes
     */
    public function __construct(
        public string $stepKey,
        array $acceptableOutcomes = ['completed'],
    ) {
        if ($this->stepKey === '' || $acceptableOutcomes === []) {
            throw new InvalidArgumentException('A process dependency needs a step key and at least one acceptable outcome.');
        }

        $validated = [];

        foreach ($acceptableOutcomes as $outcome) {
            if (! is_string($outcome) || trim($outcome) === '') {
                throw new InvalidArgumentException('Process dependency outcomes must be non-empty strings.');
            }

            $validated[] = $outcome;
        }

        /** @var non-empty-list<non-empty-string> $validated */
        $this->acceptableOutcomes = $validated;
    }
}
