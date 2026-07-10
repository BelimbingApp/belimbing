<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\BridgePlan;
use App\Base\Database\Services\Bridge\BridgePackageApplier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:bridge:apply')]
class ApplyBridgePackageCommand extends Command
{
    protected $signature = 'blb:db:bridge:apply
                            {plan : Exact reviewed plan SHA-256}
                            {--package-sha256= : Exact reviewed package SHA-256}
                            {--confirm : Confirm the reviewed hashes and apply}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Apply an exact reviewed Data Bridge plan atomically';

    public function handle(BridgePackageApplier $applier): int
    {
        $plan = BridgePlan::query()->where('plan_hash', $this->argument('plan'))->firstOrFail();

        try {
            $result = $applier->apply(
                $plan,
                (string) $this->option('package-sha256'),
                (string) $this->argument('plan'),
                (bool) $this->option('confirm'),
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'package_id' => $result->packageId,
            'plan_sha256' => $result->planHash,
            'counts' => $result->counts,
            'backup' => $result->backup,
            'status' => 'applied',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info('Data Bridge plan applied.');
            $this->components->twoColumnDetail('Package ID', $result->packageId);
            $this->components->twoColumnDetail('Plan SHA-256', $result->planHash);
        }

        return self::SUCCESS;
    }
}
