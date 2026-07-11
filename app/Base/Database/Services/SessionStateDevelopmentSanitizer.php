<?php

namespace App\Base\Database\Services;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionStateDevelopmentSanitizer implements DevelopmentSanitizationContributor
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function key(): string
    {
        return 'sessions';
    }

    public function preview(): DevelopmentSanitizationResult
    {
        return $this->result($this->persistentSessionCount());
    }

    public function apply(): DevelopmentSanitizationResult
    {
        $driver = (string) config('session.driver', 'database');
        $affected = match ($driver) {
            'database' => $this->clearDatabaseSessions(),
            'file' => $this->clearFileSessions(),
            'array', 'cookie', 'null' => 0,
            default => throw DevelopmentSanitizationException::unsupportedSessionDriver($driver),
        };

        return $this->result($affected);
    }

    private function persistentSessionCount(): int
    {
        $driver = (string) config('session.driver', 'database');

        return match ($driver) {
            'database' => $this->databaseSessionQuery()->count(),
            'file' => count($this->sessionFiles()),
            'array', 'cookie', 'null' => 0,
            default => throw DevelopmentSanitizationException::unsupportedSessionDriver($driver),
        };
    }

    private function clearDatabaseSessions(): int
    {
        return $this->databaseSessionQuery()->delete();
    }

    private function clearFileSessions(): int
    {
        $files = $this->sessionFiles();

        if ($files !== []) {
            $this->files->delete($files);
        }

        return count($files);
    }

    private function databaseSessionQuery(): Builder
    {
        $table = (string) config('session.table', 'sessions');

        if (preg_match('/\A[A-Za-z_]\w*\z/', $table) !== 1 || ! Schema::hasTable($table)) {
            throw DevelopmentSanitizationException::missingTable($table);
        }

        return DB::table($table);
    }

    /** @return list<string> */
    private function sessionFiles(): array
    {
        $path = (string) config('session.files', storage_path('framework/sessions'));

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return array_map(
            fn (\SplFileInfo $file): string => $file->getPathname(),
            $this->files->files($path),
        );
    }

    private function result(int $affected): DevelopmentSanitizationResult
    {
        return new DevelopmentSanitizationResult(
            key: $this->key(),
            label: __('Application sessions'),
            affected: $affected,
            detail: __('Clear persistent login sessions so restored production sessions cannot be reused.'),
        );
    }
}
