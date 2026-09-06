<?php

namespace App\Base\System\Console\Commands;

use App\Base\Support\PhpCli;
use App\Base\System\Exceptions\GuardMutationException;
use App\Base\System\Services\GuardMutator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete one named guard line, run a Pest file, restore, and print Markdown evidence.
 */
#[AsCommand(name: 'blb:mutate')]
class MutateCommand extends Command
{
    protected $description = 'Temporarily delete one matching source line, run a Pest file, restore, and print Markdown mutation evidence';

    protected $signature = 'blb:mutate
        {file : Source file whose guard line will be deleted}
        {matcher : Exact line number or unique substring pattern}
        {--test= : Pest test file to run before and after the deletion}';

    public function handle(): int
    {
        $test = $this->option('test');
        if (! is_string($test) || trim($test) === '') {
            $this->components->error('The --test option is required.');

            return self::FAILURE;
        }

        $mutator = new GuardMutator(fn (string $testPath): array => $this->runPest($testPath));

        try {
            $result = $mutator->run(
                (string) $this->argument('file'),
                (string) $this->argument('matcher'),
                $test,
            );
        } catch (GuardMutationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($result['markdown']);
        $this->components->info('Source restored; paste the Markdown block into the PR body.');

        return self::SUCCESS;
    }

    /**
     * @return array{exit_code: int, output: string}
     */
    private function runPest(string $testPath): array
    {
        $command = [
            ...PhpCli::current()->commandPrefix(),
            'vendor/pestphp/pest/bin/pest',
            $testPath,
        ];

        $process = Process::path(base_path())
            ->timeout(600)
            ->run($command);

        return [
            'exit_code' => $process->exitCode() ?? 1,
            'output' => $process->output().$process->errorOutput(),
        ];
    }
}
