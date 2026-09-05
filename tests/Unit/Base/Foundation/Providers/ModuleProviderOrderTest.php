<?php

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifestException;
use App\Base\Foundation\Providers\ProviderRegistry;
use App\Base\Foundation\ServiceProvider;
use App\Base\Foundation\Services\DomainState;
use App\Base\Support\AppPath;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

uses(TestCase::class);

function providerOrderModule(string $root, string $group, string $module, string $id, array $requires = []): string
{
    $directory = base_path(ApplicationTopology::relativePathUnder($root, $group, $module));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/composer.json', json_encode([
        'name' => $id,
        'extra' => ['blb' => ['module' => $id, 'requires-modules' => $requires]],
    ], JSON_THROW_ON_ERROR));
    $class = AppPath::toClass($directory.'/ServiceProvider.php');
    $namespace = substr($class, 0, strrpos($class, '\\'));
    file_put_contents($directory.'/ServiceProvider.php', '<?php namespace '.$namespace.';
class ServiceProvider extends \Illuminate\Support\ServiceProvider {
    public function boot(): void {
        $events = $this->app->make("provider-order-events");
        $events[] = "'.$id.'";
        $this->app->instance("provider-order-events", $events);
    }
}');

    return $class;
}

beforeEach(function (): void {
    $this->orderGroup = 'ZzProviderOrder'.bin2hex(random_bytes(6));
    $this->orderState = storage_path('framework/testing/'.$this->orderGroup.'.json');
    DomainState::useStatePath($this->orderState);
});

afterEach(function (): void {
    DomainState::useStatePath(null);
    File::delete($this->orderState);
    File::deleteDirectory(ApplicationTopology::domainPath($this->orderGroup));
    File::deleteDirectory(ApplicationTopology::extensionPath($this->orderGroup));
});

test('declared dependencies boot before dependents through a providerless module', function (): void {
    $dependent = providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'ADependent', 'order/dependent', ['order/bridge' => '*']);
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Bridge', 'order/bridge', ['order/dependency' => '*']);
    $dependency = providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'ZDependency', 'order/dependency');
    File::delete(ApplicationTopology::domainPath($this->orderGroup).'/Bridge/ServiceProvider.php');
    $unrelated = providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Middle', 'order/unrelated');
    $this->app->instance('provider-order-events', []);

    $providers = array_values(array_intersect(ProviderRegistry::resolve(), [$dependent, $dependency, $unrelated]));
    foreach ($providers as $provider) {
        $this->app->register($provider);
    }

    expect($this->app->make('provider-order-events'))->toBe(['order/unrelated', 'order/dependency', 'order/dependent'])
        ->and(array_values(array_intersect(ProviderRegistry::resolve(), [$dependent, $dependency, $unrelated])))->toBe($providers);
});

test('a dependency cycle names the actual cycle and is ignored only when its domain is disabled', function (): void {
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'A', 'order/a', ['order/z' => '*']);
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Z', 'order/z', ['order/a' => '*']);

    expect(fn () => ProviderRegistry::resolve())->toThrow(ModuleManifestException::class, 'order/a -> order/z -> order/a');

    DomainState::disable($this->orderGroup);
    expect(array_filter(ProviderRegistry::resolve(), fn (string $provider): bool => str_contains($provider, $this->orderGroup)))->toBe([]);
});

test('a declared dependency cannot invert the four-root framework order', function (): void {
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Dependent', 'order/dependent', ['order/extension' => '*']);
    providerOrderModule(ApplicationTopology::EXTENSIONS, $this->orderGroup, 'Dependency', 'order/extension');

    expect(fn () => ProviderRegistry::resolve())->toThrow(ModuleManifestException::class, 'order/dependent cannot require later-root module order/extension');
});

test('a missing required module cannot silently disappear from boot ordering', function (): void {
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Dependent', 'order/dependent', ['order/missing' => '*']);

    expect(fn () => ProviderRegistry::resolve())->toThrow(ModuleManifestException::class, 'order/missing');
});

test('an incompatible module version is refused during provider resolution', function (): void {
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Dependent', 'order/dependent', ['order/dependency' => '^2.0']);
    providerOrderModule(ApplicationTopology::DOMAINS, $this->orderGroup, 'Dependency', 'order/dependency');

    expect(fn () => ProviderRegistry::resolve())->toThrow(ModuleManifestException::class, 'order/dependency (incompatible; constraint ^2.0)');
});

test('Foundation logs the loaded provider sequence at debug after boot', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('debug')->once()->with('Application providers booted.', [
        'providers' => array_keys($this->app->getLoadedProviders()),
    ]);
    $this->app->instance('log', $logger);

    (new ServiceProvider($this->app))->boot();
});
