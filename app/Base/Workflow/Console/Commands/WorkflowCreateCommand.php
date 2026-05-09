<?php
namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Models\Workflow;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Register a new workflow (flow) in the process registry.
 */
#[AsCommand(name: 'blb:workflow:create')]
class WorkflowCreateCommand extends Command
{
    protected $signature = 'blb:workflow:create
                            {--code= : Unique workflow code (e.g., leave_application)}
                            {--label= : Human-readable label}
                            {--module= : Owning module (e.g., hr, logistics)}
                            {--description= : Description of the workflow}
                            {--model-class= : Eloquent model class (FQCN)}';

    protected $description = 'Register a new workflow in the process registry';

    public function handle(): int
    {
        $code = $this->option('code') ?? $this->ask('Workflow code');

        if (! $code) {
            $this->components->error('Workflow code is required.');

            return Command::FAILURE;
        }

        if (Workflow::query()->where('code', $code)->exists()) {
            $this->components->error("Workflow '{$code}' already exists.");

            return Command::FAILURE;
        }

        $label = $this->option('label') ?? $this->ask('Label', ucwords(str_replace('_', ' ', $code)));

        $workflow = Workflow::query()->create([
            'code' => $code,
            'label' => $label,
            'module' => $this->option('module'),
            'description' => $this->option('description'),
            'model_class' => $this->option('model-class'),
        ]);

        $this->components->info("Workflow '{$workflow->code}' created (id: {$workflow->id}).");

        return Command::SUCCESS;
    }
}
