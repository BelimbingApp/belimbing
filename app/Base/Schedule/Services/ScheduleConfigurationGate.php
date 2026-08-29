<?php

namespace App\Base\Schedule\Services;

use Illuminate\Support\Facades\DB;

/**
 * Serializes scheduler configuration decisions for one durable task key.
 *
 * The gate contains no configuration state. Its permanent source/key row is
 * only a stable lock target, so an editor that captured "no override" cannot
 * report a successful reset while another editor is creating an override for
 * the same task.
 */
class ScheduleConfigurationGate
{
    public function synchronize(string $key, callable $operation): mixed
    {
        return DB::transaction(function () use ($key, $operation): mixed {
            // A bounded PostgreSQL wait makes an unexpectedly held gate fail
            // honestly instead of indefinitely consuming a web worker. SQLite
            // already serializes its single writer in local/test deployments.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
            }

            DB::table('base_schedule_configuration_locks')->insertOrIgnore([
                'source' => 'scheduler',
                'key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('base_schedule_configuration_locks')
                ->where('source', 'scheduler')
                ->where('key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            return $operation();
        }, 3);
    }
}
