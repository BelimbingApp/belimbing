<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\BridgeReceiveGrant;
use App\Base\Database\Services\Bridge\BridgeReceiveGrantManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:db:bridge:grant-revoke')]
class RevokeBridgeReceiveGrantCommand extends Command
{
    protected $signature = 'blb:db:bridge:grant-revoke {grant : Public receive grant ID}';

    protected $description = 'Revoke an unconsumed one-time Data Bridge receive key';

    public function handle(BridgeReceiveGrantManager $grants): int
    {
        $grant = BridgeReceiveGrant::query()->where('grant_id', (string) $this->argument('grant'))->firstOrFail();
        $grants->revoke($grant);
        $this->components->info('Receive key revoked.');

        return self::SUCCESS;
    }
}
