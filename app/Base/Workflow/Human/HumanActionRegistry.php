<?php

namespace App\Base\Workflow\Human;

use App\Base\Workflow\Human\DTO\HumanActionDefinition;
use Illuminate\Database\Eloquent\Model;

class HumanActionRegistry
{
    /** @var array<class-string<Model>, array<string, HumanActionDefinition>> */
    private array $definitions = [];

    /** @param class-string<Model> $subjectType */
    public function register(string $subjectType, HumanActionDefinition ...$actions): void
    {
        foreach ($actions as $action) {
            if (isset($this->definitions[$subjectType][$action->key])) {
                throw new HumanActionException("Human action [{$action->key}] is already registered for [{$subjectType}].");
            }
            $this->definitions[$subjectType][$action->key] = $action;
        }
    }

    /** @return array<string, HumanActionDefinition> */
    public function for(Model $subject): array
    {
        return $this->definitions[$subject::class] ?? [];
    }

    public function get(Model $subject, string $key): HumanActionDefinition
    {
        return $this->for($subject)[$key] ?? throw new HumanActionException('Unknown human action.');
    }
}
