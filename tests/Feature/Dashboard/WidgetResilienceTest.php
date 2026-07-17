<?php

use App\Base\Dashboard\Widget;
use App\Base\Livewire\ComponentDiscoveryException;
use App\Base\Livewire\ComponentDiscoveryService;
use App\Base\Perf\Livewire\Widgets\RequestHealth;
use App\Base\System\Services\ReportedErrorRecorder;
use App\Base\ZzBrokenFixture\Livewire\Broken;
use App\Modules\Core\AI\Livewire\Widgets\OperationsStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

const DISCOVERY_FIXTURE_DIR = 'app/Base/ZzBrokenFixture';

beforeEach(function (): void {
    app(ReportedErrorRecorder::class)->clear();
});

/**
 * A widget whose data layer blows up (bad query, class missing after a
 * partial cross-repo update) — the base render guard must absorb it.
 */
class ThrowingFixtureWidget extends Widget
{
    protected function content(): View
    {
        throw new RuntimeException('widget data layer exploded');
    }
}

/**
 * A widget authored against the old contract gap: no content(), no render().
 */
class ContractlessFixtureWidget extends Widget {}

afterEach(function (): void {
    File::deleteDirectory(base_path(DISCOVERY_FIXTURE_DIR));
    app(ReportedErrorRecorder::class)->clear();
});

test('a widget that throws renders the inline failure card instead of erroring', function (): void {
    Exceptions::fake();

    Livewire::test(ThrowingFixtureWidget::class)
        ->assertOk()
        ->assertSee(__("This widget couldn't load"))
        ->assertSee(__('The error has been reported. The rest of the dashboard is unaffected.'));

    Exceptions::assertReported(RuntimeException::class);
});

test('a widget without content() degrades to the failure card too', function (): void {
    Exceptions::fake();

    Livewire::test(ContractlessFixtureWidget::class)
        ->assertOk()
        ->assertSee(__("This widget couldn't load"));

    Exceptions::assertReported(LogicException::class);
});

test('widget content is not exposed as a public Livewire action', function (): void {
    expect((new ReflectionMethod(RequestHealth::class, 'content'))->isProtected())->toBeTrue()
        ->and((new ReflectionMethod(OperationsStatus::class, 'content'))->isProtected())->toBeTrue()
        ->and(fn () => Livewire::test(ThrowingFixtureWidget::class)->call('content'))
        ->toThrow(MethodNotFoundException::class);
});

test('component discovery skips a class that fails to link instead of taking the site down', function (): void {
    $directory = base_path(DISCOVERY_FIXTURE_DIR.'/Livewire');
    File::ensureDirectoryExists($directory);

    // References an interface that does not exist — loading this class
    // throws \Error, exactly like a module updated ahead of its sibling repo.
    File::put($directory.'/Broken.php', <<<'PHP'
    <?php

    namespace App\Base\ZzBrokenFixture\Livewire;

    use Livewire\Component;

    class Broken extends Component implements \App\Base\ZzBrokenFixture\MissingContract
    {
        public function render()
        {
            return view('livewire.zz-broken-fixture.broken');
        }
    }
    PHP);

    $components = app(ComponentDiscoveryService::class)->discover();

    expect($components)->not->toContain(Broken::class);
    // Healthy components are still discovered around the broken one.
    expect($components)->toHaveKey('notifications.bell');

    // The skip is reported, not log-file-only: it reaches the recorder
    // behind the status-bar diagnostics bubble.
    $recorded = array_column(app(ReportedErrorRecorder::class)->recent(), 'exception');
    expect($recorded)->toContain(ComponentDiscoveryException::class);
});
