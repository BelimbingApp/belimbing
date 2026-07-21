<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Exceptions\DataShareMirrorException;

class DataShareMirrorOperationLock
{
    private const LOCK_NAMESPACE = 1936482669;

    private const LOCK_ID = 20260720;

    public function __construct(private readonly DataShareMirrorConnectionManager $connections) {}

    /** @template TReturn @param callable(): TReturn $operation @return TReturn */
    public function run(callable $operation): mixed
    {
        $connection = $this->connections->mirror();
        $timeoutMs = max(100, min(60000, (int) config('data_share.mirror.lock_timeout_ms', 30000)));
        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);
        $acquired = false;

        do {
            $row = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::LOCK_NAMESPACE, self::LOCK_ID]);
            $acquired = filter_var($row->acquired ?? false, FILTER_VALIDATE_BOOL);
            if (! $acquired) {
                usleep(100_000);
            }
        } while (! $acquired && hrtime(true) < $deadline);

        if (! $acquired) {
            throw DataShareMirrorException::lockUnavailable();
        }

        try {
            return $operation();
        } finally {
            $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, self::LOCK_ID]);
        }
    }
}
