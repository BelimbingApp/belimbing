<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeScopeDefinition;
use App\Base\Database\DTO\Bridge\BridgeTableDefinition;
use App\Base\Database\Exceptions\BridgePackageException;
use App\Base\Database\Models\BridgePlan;
use App\Base\Database\Models\BridgePlanAction;
use App\Base\Database\Models\BridgeReceipt;
use Illuminate\Support\Facades\DB;
use Throwable;

class BridgeImportPlanner
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgePackageVerifier $verifier,
        private readonly BridgePackageReader $reader,
        private readonly BridgeDestinationMapper $destination,
        private readonly BridgeEventRecorder $events,
    ) {}

    public function plan(BridgeReceipt $receipt): BridgePlan
    {
        $receipt->loadMissing('grant');
        $verified = $this->verifier->verifyPath($receipt->package_path, $receipt->grant);

        if (! hash_equals($receipt->package_sha256, $verified->sha256)) {
            throw BridgePackageException::invalidPackage(__('the Incoming receipt hash no longer matches.'));
        }

        return DB::transaction(function () use ($receipt, $verified): BridgePlan {
            $plan = BridgePlan::query()->create([
                'receipt_id' => $receipt->id,
                'plan_hash' => hash('sha256', uniqid('bridge-plan-', true)),
                'package_sha256' => $receipt->package_sha256,
                'destination_fingerprint' => str_repeat('0', 64),
                'summary' => [],
                'status' => 'planning',
                'planned_at' => now('UTC'),
            ]);
            $this->destination->reset();
            $counts = [
                'insert' => 0,
                'unchanged' => 0,
                'conflict' => 0,
            ];
            $sequence = 0;
            $actionHash = hash_init('sha256');
            $buffer = [];
            $stream = $this->storage->disk()->readStream($receipt->package_path);

            if ($stream === false) {
                throw BridgePackageException::invalidPackage(__('the Incoming package cannot be opened for planning.'));
            }

            try {
                $inspected = $this->reader->inspect(
                    $stream,
                    function (
                        BridgeScopeDefinition $scope,
                        BridgeTableDefinition $table,
                        array $record,
                    ) use ($plan, &$counts, &$sequence, &$buffer, $actionHash): void {
                        $classification = $this->destination->classify($table, $record);
                        $counts[$classification['action']]++;
                        $fingerprintAction = [
                            'sequence' => ++$sequence,
                            'scope_name' => $scope->name,
                            'table_name' => $table->table,
                            'primary_key_hash' => hash('sha256', CanonicalJson::encode($record['primary_key'])),
                            'primary_key' => CanonicalJson::encode($record['primary_key']),
                            'action' => $classification['action'],
                            'incoming_fingerprint' => $record['fingerprint'],
                            'destination_fingerprint' => $classification['destination_fingerprint'],
                        ];
                        hash_update($actionHash, CanonicalJson::encode($fingerprintAction));
                        $buffer[] = ['plan_id' => $plan->id, ...$fingerprintAction];

                        if (count($buffer) >= 500) {
                            BridgePlanAction::query()->insert($buffer);
                            $buffer = [];
                        }
                    },
                );
            } catch (Throwable $e) {
                fclose($stream);
                throw $e;
            }

            fclose($stream);

            if (! hash_equals($verified->sha256, $inspected->sha256)) {
                throw BridgePackageException::invalidPackage(__('the package changed while its plan was built.'));
            }

            if ($buffer !== []) {
                BridgePlanAction::query()->insert($buffer);
            }

            $destinationFingerprint = hash_final($actionHash);
            $summary = [
                'counts' => $counts,
                'records' => $sequence,
                'tables' => $verified->manifest['counts']['tables'],
            ];
            $planHash = hash('sha256', CanonicalJson::encode([
                'package_sha256' => $receipt->package_sha256,
                'destination_fingerprint' => $destinationFingerprint,
                'summary' => $summary,
            ]));
            $plan->forceFill([
                'plan_hash' => $planHash,
                'destination_fingerprint' => $destinationFingerprint,
                'summary' => $summary,
                'status' => $counts['conflict'] > 0 ? 'conflicts' : 'ready',
            ])->save();
            $receipt->forceFill(['status' => 'planned'])->save();
            $this->events->record('planned', $plan, ['counts' => $counts]);

            return $plan->refresh();
        });
    }
}
