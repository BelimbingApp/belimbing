<?php

namespace App\Base\Workflow\Process\Definitions;

use InvalidArgumentException;

final readonly class ProcessDependency
{
    /**
     * @param  non-empty-list<string>  $acceptableOutcomes
     */
    public function __construct(
        public string $stepKey,
        public array $acceptableOutcomes = ['completed'],
    ) {
        if ($this->stepKey === '' || $this->acceptableOutcomes === []) {
            throw new InvalidArgumentException('A process dependency needs a step key and at least one acceptable outcome.');
        }

        foreach ($this->acceptableOutcomes as $outcome) {
            if (! is_string($outcome) || trim($outcome) === '') {
                throw new InvalidArgumentException('Process dependency outcomes must be non-empty strings.');
            }
        }
    }
}
