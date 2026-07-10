<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Services\Bridge\BridgeImportPlanner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:bridge:plan')]
class PlanBridgePackageCommand extends Command
{
    protected $signature = 'blb:db:bridge:plan {package : Incoming package ID} {--json : Emit machine-readable JSON}';

    protected $description = 'Build and persist a non-mutating Data Bridge import plan';

    public function handle(BridgeImportPlanner $planner): int
    {
        $receipt = BridgeReceipt::query()->where('package_id', $this->argument('package'))->firstOrFail();

        try {
            $plan = $planner->plan($receipt);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'package_id' => $receipt->package_id,
            'package_sha256' => $receipt->package_sha256,
            'plan_sha256' => $plan->plan_hash,
            'status' => $plan->status,
            'summary' => $plan->summary,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info('Plan built. No domain data was changed.');
            $this->components->twoColumnDetail('Plan SHA-256', $plan->plan_hash);

            foreach ($plan->summary['counts'] as $action => $count) {
                $this->components->twoColumnDetail(ucfirst($action), (string) $count);
            }
        }

        return $plan->status === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
