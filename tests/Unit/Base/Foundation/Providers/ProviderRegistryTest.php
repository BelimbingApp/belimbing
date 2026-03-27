<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes mixed path separators when resolving extension providers', function (): void {
    $method = new ReflectionMethod(ProviderRegistry::class, 'extensionClassFromPath');
    $method->setAccessible(true);

    $basePath = str_replace('/', '\\', base_path('extensions'));
    $path = $basePath.'/sb-group\\qac/ServiceProvider.php';

    expect($method->invoke(null, $path))
        ->toBe('Extensions\\SbGroup\\Qac\\ServiceProvider');
});
