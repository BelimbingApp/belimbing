#!/usr/bin/env php
<?php

declare(strict_types=1);

$path = $argv[1] ?? '';

try {
    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "extension-conformance: cannot read {$path}: {$exception->getMessage()}\n");

    exit(1);
}

$id = is_array($manifest) ? ($manifest['extra']['blb']['id'] ?? null) : null;
if (! is_string($id) || preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $id) !== 1) {
    fwrite(STDERR, "extension-conformance: invalid extra.blb.id in {$path}\n");

    exit(1);
}
