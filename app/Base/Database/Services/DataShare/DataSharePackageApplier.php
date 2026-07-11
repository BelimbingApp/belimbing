<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareApplyResult;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareScopeDefinition;
use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\Exceptions\DataShareApplyException;
use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataSharePlanAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DataSharePackageApplier
{
    public function __construct(
        private readonly DataSharePrivateStorage $storage,
        private readonly DataSharePackageReader $reader,
        private readonly DataSharePackageVerifier $verifier,
        private readonly DataShareDestinationMapper $destination,
        private readonly DataShareScopeCatalog $catalog,
        private readonly DataShareRecoveryPoint $recovery,
        private readonly DataShareEventRecorder $events,
    ) {}

    public function apply(
        DataSharePlan $plan,
        string $expectedPackageHash,
        string $expectedPlanHash,
        bool $confirmed,
    ): DataShareApplyResult {
        if (! $confirmed) {
            throw DataShareApplyException::confirmationRequired();
        }

        $plan->load('receipt');
        $receipt = $plan->receipt;

        if (! hash_equals($plan->plan_hash, $expectedPlanHash)
            || ! hash_equals($receipt->package_sha256, $expectedPackageHash)) {
            throw DataShareApplyException::hashMismatch();
        }

        if ($receipt->status === 'applied' || $plan->status === 'applied') {
            throw DataShareApplyException::replay($receipt->package_id);
        }

        if ($plan->status !== 'ready') {
            throw DataShareApplyException::planNotReady($plan->status);
        }

        $lock = Cache::lock('base:data-share:apply', 900);

        if (! $lock->get()) {
            throw DataShareApplyException::locked();
        }

        try {
            $this->assertFresh($plan, mutate: false);
            $backup = $this->recovery->create();

            return DB::transaction(function () use ($plan, $backup): DataShareApplyResult {
                $this->assertFresh($plan, mutate: true);
                $this->synchronizeSequences($plan->receipt->metadata['tables'] ?? []);
                $summary = $plan->summary;
                $summary['backup'] = $backup;
                $plan->forceFill([
                    'summary' => $summary,
                    'status' => 'applied',
                    'applied_at' => now('UTC'),
                ])->save();
                $plan->receipt->forceFill(['status' => 'applied'])->save();
                $this->events->record('applied', $plan, [
                    'counts' => $summary['counts'],
                    'backup' => $backup,
                ]);

                return new DataShareApplyResult(
                    $plan->receipt->package_id,
                    $plan->plan_hash,
                    $summary['counts'],
                    $backup,
                );
            }, 1);
        } catch (Throwable $e) {
            $this->events->record('apply_failed', $plan, error: $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function assertFresh(DataSharePlan $plan, bool $mutate): void
    {
        $receipt = $plan->receipt;
        $verified = $this->verifier->verifyPath($receipt->package_path, DataSharePackageExpectation::fromReceipt($receipt));

        if (! hash_equals($receipt->package_sha256, $verified->sha256)) {
            throw DataShareApplyException::stalePlan();
        }

        $this->destination->reset();
        $sequence = 0;
        $actionHash = hash_init('sha256');
        $actionBuffer = [];
        $bufferStart = 0;
        $stream = $this->storage->disk()->readStream($receipt->package_path);

        if ($stream === false) {
            throw DataSharePackageException::invalidPackage(__('the Incoming package cannot be opened for apply.'));
        }

        try {
            $this->reader->inspect(
                $stream,
                function (
                    DataShareScopeDefinition $scope,
                    DataShareTableDefinition $table,
                    array $record,
                ) use ($plan, $mutate, &$sequence, &$actionBuffer, &$bufferStart, $actionHash): void {
                    $sequence++;

                    if ($actionBuffer === [] || $sequence < $bufferStart || $sequence >= $bufferStart + count($actionBuffer)) {
                        $bufferStart = $sequence;
                        $actionBuffer = DataSharePlanAction::query()
                            ->where('plan_id', $plan->id)
                            ->whereBetween('sequence', [$sequence, $sequence + 499])
                            ->orderBy('sequence')
                            ->get()
                            ->all();
                    }

                    $expected = $actionBuffer[$sequence - $bufferStart] ?? null;
                    $classification = $this->destination->classify($table, $record);

                    if ($expected === null
                        || $expected->sequence !== $sequence
                        || $expected->scope_name !== $scope->name
                        || $expected->table_name !== $table->table
                        || ! hash_equals($expected->primary_key_hash, hash('sha256', CanonicalJson::encode($record['primary_key'])))
                        || $expected->action !== $classification['action']
                        || ! hash_equals($expected->incoming_fingerprint, $record['fingerprint'])
                        || $expected->destination_fingerprint !== $classification['destination_fingerprint']) {
                        throw DataShareApplyException::stalePlan();
                    }

                    hash_update($actionHash, CanonicalJson::encode([
                        'sequence' => $sequence,
                        'scope_name' => $scope->name,
                        'table_name' => $table->table,
                        'primary_key_hash' => $expected->primary_key_hash,
                        'primary_key' => CanonicalJson::encode($record['primary_key']),
                        'action' => $classification['action'],
                        'incoming_fingerprint' => $record['fingerprint'],
                        'destination_fingerprint' => $classification['destination_fingerprint'],
                    ]));

                    if ($mutate) {
                        $this->applyRecord($table, $record, $classification['action']);
                    }
                },
            );
        } finally {
            fclose($stream);
        }

        if ($sequence !== $plan->actions()->count()
            || ! hash_equals($plan->destination_fingerprint, hash_final($actionHash))) {
            throw DataShareApplyException::stalePlan();
        }
    }

    /** @param array<string, mixed> $record */
    private function applyRecord(DataShareTableDefinition $table, array $record, string $action): void
    {
        if ($action === 'unchanged') {
            return;
        }

        if ($action !== 'insert') {
            throw DataShareApplyException::planNotReady($action);
        }

        $desired = $this->destination->desiredValues($table, $record);
        DB::table($table->table)->insert($desired);

        $stored = $this->destination->findExisting($table, $record);

        if ($stored === null) {
            throw DataShareApplyException::stalePlan();
        }

        $this->destination->remember($table, $stored);
    }

    /** @param list<string> $tables */
    private function synchronizeSequences(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $tableName) {
            $table = $this->catalog->table($tableName);

            if (count($table->primaryKeyColumns) !== 1) {
                continue;
            }

            $column = $table->primaryKeyColumns[0];
            $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$tableName, $column])?->name;

            if (! is_string($sequence) || $sequence === '') {
                continue;
            }

            $maximum = DB::table($tableName)->max($column);
            DB::selectOne('SELECT setval(?, ?, ?) AS value', [
                $sequence,
                $maximum ?? 1,
                $maximum !== null,
            ]);
        }
    }
}
