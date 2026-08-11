<?php

use App\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Core\AI\DTO\Messaging\InboundMessage;
use App\Core\AI\Enums\SignalAuthenticityStatus;
use App\Core\AI\Exceptions\WebhookAuthenticityException;
use App\Core\AI\Jobs\ProcessInboundSignalJob;
use App\Core\AI\Models\ChannelConversation;
use App\Core\AI\Models\InboundSignal;
use App\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Core\AI\Services\Messaging\InboundRoutingService;
use App\Core\AI\Services\Messaging\InboundSignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

const MESSAGING_AUTHENTICITY_WEBHOOK_PATH = '/webhook';

function registerAuthenticityTestAdapter(SignalAuthenticityStatus $status): ChannelAdapter
{
    $adapter = Mockery::mock(ChannelAdapter::class);
    $adapter->shouldReceive('channelId')->once()->andReturn('testchannel');
    $adapter->shouldReceive('verifyAuthenticity')->andReturn($status);

    app(ChannelAdapterRegistry::class)->register($adapter);

    return $adapter;
}

test('unknown messaging webhook channel returns unauthorized without dispatching a job', function (): void {
    Queue::fake();

    $this->postJson(route('ai.messaging.webhook', ['channel' => 'unknownchannel']), ['event' => 'message'])
        ->assertUnauthorized()
        ->assertJson([
            'status' => 'rejected',
            'reason' => 'unknown_channel',
        ]);

    Queue::assertNothingPushed();
});

test('messaging webhook rejects a failed authenticity check without dispatching a job', function (): void {
    Queue::fake();
    registerAuthenticityTestAdapter(SignalAuthenticityStatus::Failed);

    $this->postJson(route('ai.messaging.webhook', ['channel' => 'testchannel']), ['event' => 'message'])
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'authenticity_failed');

    Queue::assertNothingPushed();
});

test('messaging webhook rejects a skipped authenticity check without dispatching a job', function (): void {
    Queue::fake();
    registerAuthenticityTestAdapter(SignalAuthenticityStatus::Skipped);

    $this->postJson(route('ai.messaging.webhook', ['channel' => 'testchannel']), ['event' => 'message'])
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'authenticity_failed');

    Queue::assertNothingPushed();
});

test('verified messaging webhook dispatches the inbound signal job', function (): void {
    Queue::fake();
    registerAuthenticityTestAdapter(SignalAuthenticityStatus::Verified);

    $this->postJson(route('ai.messaging.webhook', ['channel' => 'testchannel']), ['event' => 'message'])
        ->assertAccepted()
        ->assertJson([
            'status' => 'accepted',
            'channel' => 'testchannel',
        ]);

    Queue::assertPushed(ProcessInboundSignalJob::class, fn (ProcessInboundSignalJob $job): bool => $job->channel === 'testchannel');
});

test('inbound signal service rejects failed authenticity before persistence', function (): void {
    $registry = new ChannelAdapterRegistry;
    $adapter = Mockery::mock(ChannelAdapter::class);
    $adapter->shouldReceive('channelId')->once()->andReturn('testchannel');
    $adapter->shouldReceive('verifyAuthenticity')->once()->andReturn(SignalAuthenticityStatus::Failed);
    $adapter->shouldNotReceive('parseInbound');
    $registry->register($adapter);

    expect(fn () => (new InboundSignalService($registry))->ingest(
        'testchannel',
        Request::create(MESSAGING_AUTHENTICITY_WEBHOOK_PATH, 'POST', ['event' => 'message']),
    ))->toThrow(WebhookAuthenticityException::class);

    expect(InboundSignal::query()->count())->toBe(0);
});

test('inbound signal service rejects a channel without an adapter', function (): void {
    $service = new InboundSignalService(new ChannelAdapterRegistry);

    expect(fn () => $service->ingest(
        'unknownchannel',
        Request::create(MESSAGING_AUTHENTICITY_WEBHOOK_PATH, 'POST', ['event' => 'message']),
    ))->toThrow(WebhookAuthenticityException::class);

    expect(InboundSignal::query()->count())->toBe(0);
});

test('inbound signal service persists verified requests with verified authenticity', function (): void {
    $registry = new ChannelAdapterRegistry;
    $request = Request::create(MESSAGING_AUTHENTICITY_WEBHOOK_PATH, 'POST', ['event' => 'message']);
    $message = new InboundMessage(
        channelId: 'external-channel-id',
        sender: 'sender-123',
        content: 'Authentic message',
        messageId: 'message-456',
        conversationId: 'conversation-789',
    );
    $adapter = Mockery::mock(ChannelAdapter::class);
    $adapter->shouldReceive('channelId')->once()->andReturn('testchannel');
    $adapter->shouldReceive('verifyAuthenticity')->once()->with($request)->andReturn(SignalAuthenticityStatus::Verified);
    $adapter->shouldReceive('parseInbound')->once()->with($request)->andReturn($message);
    $registry->register($adapter);

    $signal = (new InboundSignalService($registry))->ingest('testchannel', $request);

    expect($signal)->not->toBeNull()
        ->and($signal->authenticity_status)->toBe(SignalAuthenticityStatus::Verified)
        ->and($signal->sender_identifier)->toBe('sender-123')
        ->and($signal->conversation_identifier)->toBe('conversation-789')
        ->and($signal->normalized_content)->toBe('Authentic message');

    $this->assertDatabaseHas('ai_inbound_signals', [
        'id' => $signal->id,
        'channel' => 'testchannel',
        'authenticity_status' => SignalAuthenticityStatus::Verified->value,
    ]);
});

test('inbound routing rejects signals without a company-scoped account', function (): void {
    $signal = InboundSignal::query()->create([
        'channel' => 'testchannel',
        'authenticity_status' => SignalAuthenticityStatus::Verified,
        'sender_identifier' => 'orphaned-sender',
        'normalized_content' => 'Do not route this into an arbitrary company.',
        'received_at' => now(),
    ]);

    $outcome = app(InboundRoutingService::class)->route($signal);

    expect($outcome->disposition)->toBe('rejected')
        ->and($outcome->reason)->toBe('Signal has no company-scoped channel account.')
        ->and($signal->fresh()->routed_at)->not->toBeNull()
        ->and(ChannelConversation::query()->exists())->toBeFalse();
});
