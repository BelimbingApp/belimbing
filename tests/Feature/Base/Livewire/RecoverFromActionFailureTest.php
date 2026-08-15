<?php

use App\Base\Foundation\Enums\FoundationErrorCode;
use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Base\Livewire\RecoverFromActionFailure;
use App\Base\System\Services\ReportedErrorRecorder;
use Livewire\Component;
use Livewire\Livewire;

class RecoverFromActionFailureTestComponent extends Component
{
    public string $ran = 'no';

    public function explode(): void
    {
        throw new RuntimeException('upstream went away');
    }

    public function invalid(): void
    {
        $this->validate(['ran' => 'integer']);
    }

    public function forbidden(): void
    {
        abort(403);
    }

    public function platformFailure(): void
    {
        throw new BlbInvariantViolationException('nope', FoundationErrorCode::BLB_INVARIANT_VIOLATION);
    }

    public function fine(): void
    {
        $this->ran = 'yes';
    }

    public function render(): string
    {
        return '<div>{{ $ran }}</div>';
    }
}

it('keeps the user on the page when an action fails unexpectedly', function (): void {
    Livewire::test(RecoverFromActionFailureTestComponent::class)
        ->call('explode')
        ->assertOk()
        ->assertDispatched('notify', variant: 'error');
});

it('leaves the component usable after a recovered failure', function (): void {
    // The point of recovering is that the session continues. A component that
    // survives the error but cannot act again has only moved the dead end.
    Livewire::test(RecoverFromActionFailureTestComponent::class)
        ->call('explode')
        ->call('fine')
        ->assertSet('ran', 'yes');
});

it('reports the recovered failure so it is not hidden from developers', function (): void {
    // Driven directly rather than through Livewire::test(): the test harness
    // swaps the exception handler for one that re-throws instead of reporting
    // (RequestBroker::withoutExceptionHandling), so report() is inert inside a
    // component test. Asserting through the recorder is the point — that is
    // the path that puts a recovered failure in the admin status bar, which is
    // the difference between recovering a defect and burying it.
    $recorder = app(ReportedErrorRecorder::class);
    $recorder->clear();

    $hook = new RecoverFromActionFailure;
    $hook->setComponent(new RecoverFromActionFailureTestComponent);
    $hook->call('explode', [], fn () => null, [], null);

    $stopped = false;
    $hook->exception(new RuntimeException('upstream went away'), function () use (&$stopped): void {
        $stopped = true;
    });

    expect($stopped)->toBeTrue()
        ->and(collect($recorder->recent())->pluck('message'))->toContain('upstream went away');
});

it('lets validation reach Livewire instead of swallowing it', function (): void {
    Livewire::test(RecoverFromActionFailureTestComponent::class)
        ->call('invalid')
        ->assertHasErrors('ran')
        ->assertNotDispatched('notify');
});

it('lets an abort keep its status instead of becoming a notification', function (): void {
    Livewire::test(RecoverFromActionFailureTestComponent::class)
        ->call('forbidden')
        ->assertForbidden();
});

it('lets a BLB platform exception keep its reviewed status mapping', function (): void {
    expect(fn () => Livewire::test(RecoverFromActionFailureTestComponent::class)->call('platformFailure'))
        ->toThrow(BlbInvariantViolationException::class);
});
