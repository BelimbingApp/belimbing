<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DatabaseConnectionRecovery
{
    /**
     * Handle an incoming request with database connection recovery.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (\PDOException $e) {
            // Check if it's a connection error
            if ($this->isConnectionError($e)) {
                Log::warning('Database connection error detected, attempting recovery', [
                    'error' => $e->getMessage(),
                ]);

                // Attempt to reconnect
                $this->reconnectDatabase();

                // Retry the request once
                try {
                    return $next($request);
                } catch (\Exception $retryException) {
                    Log::error('Database recovery failed', [
                        'original_error' => $e->getMessage(),
                        'retry_error' => $retryException->getMessage(),
                    ]);
                    throw $retryException;
                }
            }

            throw $e;
        }
    }

    /**
     * Check if exception is a connection error
     */
    private function isConnectionError(\PDOException $e): bool
    {
        $connectionErrors = [
            'SQLSTATE[HY000] [2002]', // Connection refused
            'SQLSTATE[HY000] [2006]', // MySQL server has gone away
            'SQLSTATE[08006]', // PostgreSQL connection errors
            'Connection refused',
            'Connection timed out',
        ];

        $message = $e->getMessage();
        foreach ($connectionErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconnect to database
     */
    private function reconnectDatabase(): void
    {
        try {
            DB::reconnect();
            // Test connection
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            Log::error('Database reconnection failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
