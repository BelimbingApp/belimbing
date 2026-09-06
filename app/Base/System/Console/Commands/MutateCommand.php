<?php

namespace App\Base\System\Console\Commands;

use App\Base\Support\PhpCli;
use App\Base\System\Exceptions\GuardMutationException;
use App\Base\System\Services\GuardMutator;
use App\Base\System\Services\ReviewMutationEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete one named guard line, run a Pest file, restore, and print Markdown evidence.
 */
#[AsCommand(name: 'blb:mutate')]
class MutateCommand extends Command
{
    protected $description = 'Temporarily delete one matching source line, run a Pest file, restore, and print Markdown mutation evidence';

    protected $signature = 'blb:mutate
        {file? : Source file whose guard line will be deleted}
        {matcher? : Exact line number or unique substring pattern}
        {--test= : Pest test file to run before and after the deletion}
        {--batch= : JSON list of {file, pattern, test} entries for sequential mutations}
        {--evidence= : Pull request number whose exact head will bind the batch review evidence}';

    public function handle(): int
    {
        $batch = $this->option('batch');
        if (is_string($batch) && trim($batch) !== '') {
            return $this->handleBatch($batch);
        }

        if ($this->hasEvidenceOption()) {
            $this->components->error('The --evidence option requires --batch=<json>.');

            return self::FAILURE;
        }

        $file = $this->argument('file');
        $matcher = $this->argument('matcher');
        if (! is_string($file) || trim($file) === '' || ! is_string($matcher) || trim($matcher) === '') {
            $this->components->error('Provide file and matcher arguments, or --batch=<json>.');

            return self::FAILURE;
        }

        $test = $this->option('test');
        if (! is_string($test) || trim($test) === '') {
            $this->components->error('The --test option is required.');

            return self::FAILURE;
        }

        $mutator = new GuardMutator(fn (string $testPath): array => $this->runPest($testPath));

        try {
            $result = $mutator->run($file, $matcher, $test);
        } catch (GuardMutationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($result['markdown']);
        $this->components->info('Source restored; paste the Markdown block into the PR body.');

        return self::SUCCESS;
    }

    private function handleBatch(string $batchJson): int
    {
        $evidence = $this->option('evidence');
        $evidenceRenderer = null;
        $evidencePrefix = null;

        if (is_string($evidence) && trim($evidence) !== '') {
            if (ctype_digit($evidence) === false || (int) $evidence < 1) {
                $this->components->error('The --evidence option must be a positive pull request number.');

                return self::FAILURE;
            }

            $agent = getenv('CLAIM_AGENT');
            if (! is_string($agent)) {
                $agent = '';
            }

            $evidenceRenderer = app(ReviewMutationEvidence::class);

            try {
                $reviewedHead = $evidenceRenderer->verify(base_path(), (int) $evidence);
                $evidencePrefix = $evidenceRenderer->format($reviewedHead, $agent, '');
            } catch (GuardMutationException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $decoded = json_decode($batchJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->components->error('Invalid --batch JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($decoded) || array_is_list($decoded) === false) {
            $this->components->error('--batch must be a JSON array of {file, pattern, test} objects.');

            return self::FAILURE;
        }

        /** @var list<mixed> $decoded */
        $entries = [];
        foreach ($decoded as $index => $entry) {
            if (! is_array($entry)) {
                $this->components->error('batch entry '.($index + 1).' must be an object.');

                return self::FAILURE;
            }
            $entries[] = [
                'file' => (string) ($entry['file'] ?? ''),
                'pattern' => (string) ($entry['pattern'] ?? ''),
                'test' => (string) ($entry['test'] ?? ''),
            ];
        }

        $mutator = new GuardMutator(fn (string $testPath): array => $this->runPest($testPath));

        try {
            $result = $mutator->runBatch($entries);
        } catch (GuardMutationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $markdown = $result['markdown'];
        if (is_string($evidencePrefix)) {
            $markdown = $evidencePrefix.ltrim($markdown);
        }

        if (! $evidenceRenderer instanceof ReviewMutationEvidence) {
            $this->newLine();
        }
        $this->line($markdown);
        $this->components->info($evidenceRenderer instanceof ReviewMutationEvidence
            ? 'All sources restored; choose the verdict and paste the review block.'
            : 'All sources restored; paste the Markdown table into the PR body.');

        return self::SUCCESS;
    }

    private function hasEvidenceOption(): bool
    {
        $evidence = $this->option('evidence');

        return is_string($evidence) && trim($evidence) !== '';
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
