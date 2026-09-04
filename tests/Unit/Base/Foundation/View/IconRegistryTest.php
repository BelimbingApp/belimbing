<?php

use Tests\TestCase;

uses(TestCase::class);

it('keeps the icon registry source in Sonar-indexable line shape', function (): void {
    $lines = file(
        base_path('app/Base/Foundation/View/IconRegistry.php'),
        FILE_IGNORE_NEW_LINES,
    );

    expect($lines)->not->toBeFalse()->not->toBeEmpty();

    $lineLengths = array_map('strlen', $lines);

    expect(array_sum($lineLengths) / count($lineLengths))->toBeLessThan(150.0)
        ->and(max($lineLengths))->toBeLessThan(300);
});
