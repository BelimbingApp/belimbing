<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:mirror-tables')]
class MirrorTablesCommand extends Command
{
    protected $signature = 'blb:db:mirror-tables
        {--direction= : Required direction: push or pull}
        {--table=* : Required exact table name; repeat for every selected table}
        {--execute : Execute after a fresh review; without this flag the command is read-only}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Review or execute an explicit provider-backed development table mirror selection';

    public function handle(DataShareMirrorManager $mirror): int
    {
        $direction = $this->option('direction');
        $tables = $this->option('table');

        if (! is_string($direction) || ! is_array($tables) || $tables === []) {
            $this->components->error('Both --direction=push|pull and at least one --table=<name> are required.');

            return self::INVALID;
        }

        try {
            $review = $mirror->review($direction, $tables);
            if (! $this->option('execute')) {
                $this->renderReview($review);

                return $review->hasBlockers ? self::FAILURE : self::SUCCESS;
            }

            if ($review->hasBlockers) {
                $this->renderReview($review);

                return self::FAILURE;
            }

            $result = $mirror->execute($direction, $tables, $review->stateToken);

            if ($this->option('json')) {
                $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->info('Selected tables mirrored successfully.');
                foreach ($result->counts as $action => $count) {
                    $this->components->twoColumnDetail(ucfirst($action), (string) $count);
                }
            }

            return self::SUCCESS;
        } catch (DataShareMirrorException $exception) {
            $message = $exception->outcomeIndeterminate
                ? $exception->getMessage().' '.__('The outcome may be indeterminate; inspect the selected destination tables before retrying.')
                : $exception->getMessage();
            $this->components->error($message);

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error(__('The mirror command could not complete safely.'));

            return self::FAILURE;
        }
    }

    private function renderReview(DataShareMirrorReview $review): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($review->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->info('Dry run only. No tables were changed.');
        $rows = [];
        foreach ($review->items as $item) {
            $rows[] = [
                $item->table,
                ucfirst($item->action->value),
                implode(' ', array_map(fn ($blocker): string => $blocker->message, $item->blockers)),
            ];
        }

        $this->table(['Table', 'Action', 'Reason'], $rows);
        $this->line('Run again with --execute only after reviewing this exact selection.');
    }
}
