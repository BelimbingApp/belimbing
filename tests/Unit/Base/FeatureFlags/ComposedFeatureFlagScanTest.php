<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('rejects a composed Domain reading a foreign flag through the PHPStan process', function (): void {
    $root = dirname(__DIR__, 4);
    $fixture = $root.'/storage/framework/testing/feature-flags-'.bin2hex(random_bytes(6));
    $files = new Filesystem;

    try {
        // Reuse the owning/direct-required/foreign declarations from the rule
        // fixtures, but place their modules under a real Domain-shaped root.
        $files->copyDirectory($root.'/tests/Fixtures/feature-flags/app/Core', $fixture.'/app/Domains/Demo');
        $files->delete($fixture.'/app/Domains/Demo/Consumer/Dynamic.php');
        $files->deleteDirectory($fixture.'/app/Domains/Demo/Unowned');
        $config = $fixture.'/phpstan.neon';
        $settings = "parameters:\n    customRulesetUsed: true\n    tmpDir: {$fixture}/cache\n    paths:\n        - {$fixture}/app/Domains\nservices:\n    -\n        class: App\\Base\\FeatureFlags\\FeatureFlagReadRule\n        arguments:\n            projectRoot: {$fixture}\n        tags:\n            - phpstan.rules.rule\n";
        file_put_contents($config, $settings);

        $scan = new Process([PHP_BINARY, $root.'/vendor/bin/phpstan', 'analyse', '-c', $config, '--no-progress', '--error-format=json', '--memory-limit=512M'], $root);
        $scan->setTimeout(60);
        $scan->run();
        expect($scan->getExitCode())->toBe(1);
        $report = json_decode($scan->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['totals']['file_errors'])->toBe(1);
        $messages = array_values($report['files'])[0]['messages'];
        expect($messages[0]['message'])->toBe('Module [demo/consumer] reads flag [foreign.flag] without declaring it or directly requiring its declaring module.');
    } finally {
        $files->deleteDirectory($fixture);
    }
});
