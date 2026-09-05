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

    // Keep the source-shape contract focused on the property Sonar needs:
    // no individual source line should look like a generated/minified blob.
    expect(max($lineLengths))->toBeLessThan(300);
});
