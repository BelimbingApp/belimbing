<?php

namespace App\Base\System\Console\Commands;

use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand as CollisionTestCommand;

class TestCommand extends CollisionTestCommand
{
    /**
     * Get the PHP binary to execute.
     *
     * FrankenPHP's CLI reports an empty PHP_BINARY constant. Prefer the wrapper
     * path exported by scripts/setup-steps/15-runtime.sh when available.
     *
     * @return array<int, string>
     */
    protected function binary(): array
    {
        $command = $this->testRunnerCommand();

        $phpBinary = $this->resolvePhpBinary();

        if ('phpdbg' === PHP_SAPI) {
            return [$phpBinary, '-qrr', ...$command];
        }

        return [$phpBinary, ...$command];
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

    private function resolvePhpBinary(): string
    {
        foreach ([getenv('PHP_BINARY'), PHP_BINARY, PHP_BINDIR.'/php', '/usr/local/bin/php'] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
