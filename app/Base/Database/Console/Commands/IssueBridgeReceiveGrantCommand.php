<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Services\Bridge\BridgeReceiveGrantManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:bridge:grant')]
class IssueBridgeReceiveGrantCommand extends Command
{
    protected $signature = 'blb:db:bridge:grant
                            {scope : Base-discovered module scope}
                            {--source-id= : Immutable expected source instance ID}
                            {--source-name= : Operator-facing expected source name}
                            {--source-role=development : Expected source role}
                            {--json : Emit only the copy-once bundle JSON}';

    protected $description = 'Issue one short-lived Data Bridge receive key on this target';

    public function handle(BridgeReceiveGrantManager $grants): int
    {
        $sourceRole = BridgeInstanceRole::tryFrom((string) $this->option('source-role'));
        $sourceId = trim((string) $this->option('source-id'));

        if ($sourceRole === null || $sourceId === '') {
            $this->components->error('--source-id and a valid --source-role are required.');

            return self::INVALID;
        }

        try {
            $bundle = $grants->issue(
                new BridgeInstanceIdentity(
                    $sourceId,
                    trim((string) $this->option('source-name')) ?: $sourceId,
                    $sourceRole,
                ),
                (string) $this->argument('scope'),
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->components->warn('Copy this receive key now. Its plaintext secret is not stored and cannot be shown again.');
        }

        $this->line($bundle->toJson());

        return self::SUCCESS;
    }
}
