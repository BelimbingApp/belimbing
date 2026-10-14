<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Console\TenantScopedCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

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
            if (! is_string($name)) {
                continue;
            }

            $row = $this->rowForCommand($name, $command);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [
                $left['domain'],
                $left['module'],
                $left['name'],
                $left['class'],
            ] <=> [
                $right['domain'],
                $right['module'],
                $right['name'],
                $right['class'],
            ],
        );

        return $rows;
    }

    /**
     * @return array{
     *     domain: string,
     *     module: string,
     *     name: string,
     *     class: class-string,
     *     tenant_scoped: bool
     * }|null
     */
    private function rowForCommand(string $name, SymfonyCommand $command): ?array
    {
        if ($name === '') {
            return null;
        }

        $class = $command::class;

        if (! str_starts_with($class, 'App\\Domains\\')) {
            return null;
        }

        $owner = $this->ownerFromClass($class);
        if ($owner === null) {
            return null;
        }

        return [
            'domain' => $owner['domain'],
            'module' => $owner['module'],
            'name' => $name,
            'class' => $class,
            'tenant_scoped' => is_a($command, TenantScopedCommand::class),
        ];
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

        $domain = $parts[2];
        $module = $parts[3];
        if ($domain === '' || $module === '') {
            return null;
        }

        return [
            'domain' => $domain,
            'module' => $module,
        ];
    }
}
