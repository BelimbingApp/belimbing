<?php

namespace App\Base\Database\Contracts;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProcessResult;

interface DataShareMirrorProcessRunner
{
    public function find(string $executable): ?string;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, array $environment = [], int $timeout = 30): DataShareMirrorProcessResult;
}
