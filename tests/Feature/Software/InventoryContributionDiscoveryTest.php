<?php

use App\Base\Software\Inventory\Contracts\InventoryContributionProvider;
use App\Base\Software\Inventory\ContributionSummary;
use App\Base\Software\Services\InventoryContributionDiscoveryService;
use App\Base\Software\Services\InventoryContributionRegistry;
use Illuminate\Support\Facades\File;

class FakeInventoryContributionProvider implements InventoryContributionProvider
{
    public function contributions(): array
    {
        return [new ContributionSummary(
            hostModule: 'people/payroll',
            seam: 'payroll.country-pack',
            kind: ContributionSummary::KIND_ADAPTER,
            label: 'Fake MY pack',
            providerModule: 'people/payroll',
        )];
    }
}

class NotAnInventoryContributionProvider
{
    public function contributions(): array
    {
        return [];
    }
}

function writeInventoryConfig(string $body): string
{
    $root = storage_path('framework/testing/inventory-'.bin2hex(random_bytes(4)));
    $dir = $root.'/People/Payroll/Config';
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/inventory.php', $body);

    return $root;
}

it('discovers and registers contribution providers declared in Config/inventory.php', function (): void {
    $root = writeInventoryConfig('<?php return [\'contribution_providers\' => [\\'.FakeInventoryContributionProvider::class.'::class]];');

    try {
        $registry = new InventoryContributionRegistry;
        (new InventoryContributionDiscoveryService([$root.'/*/*/Config/inventory.php']))->discoverInto($registry);

        expect($registry->contributions())->toHaveCount(1)
            ->and($registry->contributions()[0]->label)->toBe('Fake MY pack');
    } finally {
        File::deleteDirectory($root);
    }
});

it('ignores classes that do not implement the provider contract', function (): void {
    $root = writeInventoryConfig('<?php return [\'contribution_providers\' => [\\'.NotAnInventoryContributionProvider::class.'::class]];');

    try {
        $registry = new InventoryContributionRegistry;
        (new InventoryContributionDiscoveryService([$root.'/*/*/Config/inventory.php']))->discoverInto($registry);

        expect($registry->contributions())->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('skips a config that declares no providers', function (): void {
    $root = writeInventoryConfig('<?php return [];');

    try {
        $registry = new InventoryContributionRegistry;
        (new InventoryContributionDiscoveryService([$root.'/*/*/Config/inventory.php']))->discoverInto($registry);

        expect($registry->contributions())->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});
