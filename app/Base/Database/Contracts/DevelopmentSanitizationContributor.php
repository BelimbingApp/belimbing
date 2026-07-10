<?php

namespace App\Base\Database\Contracts;

use App\Base\Database\DTO\DevelopmentSanitizationResult;

interface DevelopmentSanitizationContributor
{
    public const CONTAINER_TAG = 'database.development-sanitizers';

    public function key(): string;

    public function preview(): DevelopmentSanitizationResult;

    public function apply(): DevelopmentSanitizationResult;
}
