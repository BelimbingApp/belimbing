#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach (array_slice($argv, 1) as $path) {
    try {
        token_get_all((string) file_get_contents($path), TOKEN_PARSE);
    } catch (Throwable $exception) {
        fwrite(STDERR, "extension-conformance: invalid PHP syntax in {$path}: {$exception->getMessage()}\n");

        exit(1);
    }
}
