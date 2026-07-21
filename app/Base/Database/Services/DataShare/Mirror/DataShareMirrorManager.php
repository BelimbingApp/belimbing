<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use Throwable;

class DataShareMirrorManager
{
    public function __construct(
        private readonly DataShareMirrorConnectionManager $connections,
        private readonly DataShareMirrorCatalog $catalog,
        private readonly DataShareMirrorReviewer $reviewer,
        private readonly DataShareMirrorEngineRegistry $engines,
        private readonly DataShareMirrorOperationLock $operationLock,
    ) {}

    public function status(): DataShareMirrorConnectionStatus
    {
        return $this->connections->status();
    }

    public function testConnection(string $candidateUrl, ?string $provider = null): DataShareMirrorConnectionStatus
    {
        return $this->connections->testConnection($candidateUrl, $provider);
    }

    /** @return array<string, string> */
    public function providerOptions(): array
    {
        return $this->connections->providerOptions();
    }

    public function disconnect(): void
    {
        $this->connections->purge();
    }

    /** @return list<DataShareMirrorCatalogTable> */
    public function catalog(): array
    {
        try {
            return $this->catalog->catalog();
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw DataShareMirrorException::unavailable(__('The development mirror catalog could not be inspected.'));
        }
    }

    /** @param list<string> $tableNames */
    public function review(string $direction, array $tableNames): DataShareMirrorReview
    {
        try {
            return $this->reviewer->review(DataShareMirrorDirection::parse($direction), $tableNames);
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw DataShareMirrorException::unavailable(__('The selected tables could not be reviewed safely.'));
        }
    }

    /**
     * Recompute policy immediately before mutation. Web callers pass the
     * transient token from their visible review; CLI callers may omit it only
     * when review and execute happen in the same command invocation.
     *
     * @param  list<string>  $tableNames
     */
    public function execute(string $direction, array $tableNames, ?string $expectedStateToken = null): DataShareMirrorExecutionResult
    {
        try {
            return $this->operationLock->run(function () use ($direction, $tableNames, $expectedStateToken): DataShareMirrorExecutionResult {
                $review = $this->review($direction, $tableNames);

                if ($review->hasBlockers) {
                    throw DataShareMirrorException::blocked();
                }

                if ($expectedStateToken !== null && ! hash_equals($review->stateToken, $expectedStateToken)) {
                    throw DataShareMirrorException::staleReview();
                }

                return $this->engines->forMode($this->status()->transferMode)->execute($review);
            });
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw DataShareMirrorException::processFailed(__('Mirror engine'));
        }
    }
}
