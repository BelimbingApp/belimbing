<?php

namespace App\Base\System\Console\Commands;

use App\Base\Support\PhpCli;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand as CollisionTestCommand;

class TestCommand extends CollisionTestCommand
{
    /**
     * Get the PHP CLI command to execute.
     *
     * The shared resolver handles shell wrappers, Windows FrankenPHP sidecars,
     * and FrankenPHP's php-cli fallback.
     *
     * @return array<int, string>
     */
    protected function binary(): array
    {
        $command = $this->testRunnerCommand();

        $php = PhpCli::current()->commandPrefix();

        if ('phpdbg' === PHP_SAPI) {
            return [$php[0], '-qrr', ...$command];
        }

        return [...$php, ...$command];
    }

    /**
     * @return array<int, string>
     */
    private function testRunnerCommand(): array
    {
        if ($this->usingPest()) {
            return $this->option('parallel')
                ? ['vendor/pestphp/pest/bin/pest', '--parallel']
                : ['vendor/pestphp/pest/bin/pest'];
        }

        return $this->option('parallel')
            ? ['vendor/brianium/paratest/bin/paratest']
            : ['vendor/phpunit/phpunit/phpunit'];
    }
}
