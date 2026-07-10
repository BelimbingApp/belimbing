<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Services\Bridge\BridgePackageVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:bridge:inspect')]
class InspectBridgePackageCommand extends Command
{
    protected $signature = 'blb:db:bridge:inspect {package : Incoming package ID} {--json : Emit machine-readable JSON}';

    protected $description = 'Reverify an Incoming Data Bridge package without planning or mutation';

    public function handle(BridgePackageVerifier $verifier): int
    {
        $receipt = BridgeReceipt::query()->where('package_id', $this->argument('package'))->firstOrFail();

        try {
            $receipt->loadMissing('grant');
            $verified = $verifier->verifyPath($receipt->package_path, $receipt->grant);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'package_id' => $receipt->package_id,
            'sha256' => $verified->sha256,
            'bytes' => $verified->bytes,
            'manifest' => $verified->manifest,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info('Incoming package verification passed.');
            $this->components->twoColumnDetail('SHA-256', $verified->sha256);
            $this->components->twoColumnDetail('Records', (string) $verified->manifest['counts']['records']);
        }

        return self::SUCCESS;
    }
}
