<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Console\TenantScopedCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

/**
 * Lists Artisan commands owned by Domain modules (`App\Domains\...`).
 */
final class DomainCommandInventory
{
    public function __construct(
        private readonly ConsoleKernel $kernel,
    ) {}

    /**
     * @return list<array{
     *     domain: string,
     *     module: string,
     *     name: string,
     *     class: class-string,
     *     tenant_scoped: bool
     * }>
     */
    public function all(): array
    {
        // Ensure lazy command discovery has run before we inspect registrations.
        $this->kernel->all();

        $rows = [];

        foreach (Artisan::all() as $name => $command) {
            $class = $command::class;

            if (! str_starts_with($class, 'App\\Domains\\')) {
                continue;
            }

            $owner = $this->ownerFromClass($class);
            if ($owner === null) {
                continue;
            }

            $rows[] = [
                ...$owner,
                'name' => $name,
                'class' => $class,
                'tenant_scoped' => is_subclass_of($class, TenantScopedCommand::class),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $this->sortKey($left) <=> $this->sortKey($right));

        return $rows;
    }

    /**
     * @return array{domain: string, module: string}|null
     */
    private function ownerFromClass(string $class): ?array
    {
        // App\Domains\{Domain}\{Module}\...
        $parts = explode('\\', $class);
        if (count($parts) < 5 || $parts[0] !== 'App' || $parts[1] !== 'Domains') {
            return null;
        }

        return [
            'domain' => $parts[2],
            'module' => $parts[3],
        ];
    }

    /**
     * @param  array{domain: string, module: string, name: string, class: class-string}  $row
     * @return list<string>
     */
    private function sortKey(array $row): array
    {
        return [$row['domain'], $row['module'], $row['name'], $row['class']];
    }
}
