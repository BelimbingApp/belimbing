<?php

use App\Modules\Core\AI\Enums\LaraTaskType;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use Tests\TestCase;

uses(TestCase::class);

test('lara task registry exposes commerce ai assist tasks as runtime pending simple tasks', function (): void {
    $registry = app(LaraTaskRegistry::class);

    $photoCleanup = $registry->find('photo-cleanup');
    $describeItem = $registry->find('describe-item');

    expect($registry->keys())->toBe([
        'titling',
        'research',
        'photo-cleanup',
        'describe-item',
        'coding',
    ])
        ->and($photoCleanup)->not->toBeNull()
        ->and($photoCleanup->label)->toBe('Photo Cleanup')
        ->and($photoCleanup->type)->toBe(LaraTaskType::Simple)
        ->and($photoCleanup->runtimeReady)->toBeFalse()
        ->and($describeItem)->not->toBeNull()
        ->and($describeItem->label)->toBe('Describe Item')
        ->and($describeItem->type)->toBe(LaraTaskType::Simple)
        ->and($describeItem->runtimeReady)->toBeFalse();
});
