<?php

it('keeps the icon registry source in Sonar-indexable line shape', function (): void {
    $lines = file(
        dirname(__DIR__, 5).'/app/Base/Foundation/View/IconRegistry.php',
        FILE_IGNORE_NEW_LINES,
    );

    expect($lines)->not->toBeFalse()->not->toBeEmpty();

    $lineLengths = array_map('strlen', $lines);

    expect(array_sum($lineLengths) / count($lineLengths))->toBeLessThan(150.0)
        ->and(max($lineLengths))->toBeLessThan(300);
});
