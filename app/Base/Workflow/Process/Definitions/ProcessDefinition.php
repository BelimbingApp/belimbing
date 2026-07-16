<?php

namespace App\Base\Workflow\Process\Definitions;

use InvalidArgumentException;

final readonly class ProcessDefinition
{
    /** @var array<string, ProcessStep> */
    private array $stepsByKey;

    /**
     * @param  non-empty-list<ProcessStep>  $steps
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $steps,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->key) || $this->version < 1 || $this->steps === []) {
            throw new InvalidArgumentException('A process definition needs a stable lowercase key, positive version, and at least one step.');
        }

        $byKey = [];

        foreach ($this->steps as $step) {
            if (! $step instanceof ProcessStep) {
                throw new InvalidArgumentException('Process definitions may contain only ProcessStep values.');
            }

            if (isset($byKey[$step->key])) {
                throw new InvalidArgumentException("Duplicate process step [{$step->key}].");
            }

            $byKey[$step->key] = $step;
        }

        foreach ($byKey as $step) {
            foreach ($step->dependencies as $dependency) {
                if ($dependency->stepKey === $step->key || ! isset($byKey[$dependency->stepKey])) {
                    throw new InvalidArgumentException("Invalid dependency [{$dependency->stepKey}] on process step [{$step->key}].");
                }
            }
        }

        $this->assertAcyclic($byKey);
        $this->stepsByKey = $byKey;
    }

    public function step(string $key): ProcessStep
    {
        return $this->stepsByKey[$key]
            ?? throw new InvalidArgumentException("Unknown process step [{$key}].");
    }

    /**
     * Stable identity for the complete executable contract of this version.
     *
     * A definition key and version are immutable once a run exists. Persisting
     * this fingerprint lets a later deployment detect an accidental in-place
     * edit instead of silently giving two runs different behavior under the
     * same version.
     */
    public function fingerprint(): string
    {
        $steps = array_map(static fn (ProcessStep $step): array => [
            'key' => $step->key,
            'label' => $step->label,
            'dependencies' => array_map(static fn (ProcessDependency $dependency): array => [
                'step_key' => $dependency->stepKey,
                'acceptable_outcomes' => $dependency->acceptableOutcomes,
            ], $step->dependencies),
            'dependency_mode' => $step->dependencyMode->value,
            'required_signal' => $step->requiredSignal,
            'delay_seconds' => $step->delaySeconds,
            'max_attempts' => $step->maxAttempts,
            'input' => self::canonicalize($step->input),
            'executor_key' => $step->resolvedExecutorKey(),
            'metadata' => self::canonicalize($step->metadata),
            'priority' => $step->priority,
            'input_ref' => $step->inputRef,
            'result_ref' => $step->resultRef,
        ], $this->steps);

        try {
            $encoded = json_encode([
                'key' => $this->key,
                'version' => $this->version,
                'steps' => $steps,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException(
                "Process definition [{$this->key}:{$this->version}] contains values that cannot be persisted as JSON.",
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    /** @param array<string, ProcessStep> $steps */
    private function assertAcyclic(array $steps): void
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $steps): void {
            if (isset($visited[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new InvalidArgumentException("Process definition contains a dependency cycle at [{$key}].");
            }

            $visiting[$key] = true;

            foreach ($steps[$key]->dependencies as $dependency) {
                $visit($dependency->stepKey);
            }

            unset($visiting[$key]);
            $visited[$key] = true;
        };

        foreach (array_keys($steps) as $key) {
            $visit($key);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
