<?php

use App\Base\System\Events\ReverbTestMessageOccurred;
use App\Base\System\Http\Controllers\TestReverbDispatchController;
use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const FEATURE_SYSTEM_TEST_SSE_INDEX_ROUTE = 'admin.system.test-sse.index';
const FEATURE_SYSTEM_TEST_SSE_STREAM_ROUTE = 'admin.system.test-sse.stream';
const FEATURE_SYSTEM_TEST_REVERB_INDEX_ROUTE = 'admin.system.test-reverb.index';
const FEATURE_SYSTEM_TEST_REVERB_DISPATCH_ROUTE = 'admin.system.test-reverb.dispatch';
const FEATURE_SYSTEM_REVERB_CHANNEL_PREFIX = 'system.reverb-test.';

beforeEach(function (): void {
    setupAuthzRoles();
});

it('forbids transport test pages and streams without the capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route(FEATURE_SYSTEM_TEST_SSE_INDEX_ROUTE))->assertForbidden();
    $this->get(route(FEATURE_SYSTEM_TEST_SSE_STREAM_ROUTE))->assertForbidden();
    $this->get(route(FEATURE_SYSTEM_TEST_REVERB_INDEX_ROUTE))->assertForbidden();
    $this->post(route(FEATURE_SYSTEM_TEST_REVERB_DISPATCH_ROUTE))->assertForbidden();
});

it('renders the transport test pages for admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $this->get(route(FEATURE_SYSTEM_TEST_SSE_INDEX_ROUTE))
        ->assertOk()
        ->assertSee('TestSSE')
        ->assertSee('10 minutes total')
        ->assertSee(route(FEATURE_SYSTEM_TEST_SSE_STREAM_ROUTE), false);

    $this->get(route(FEATURE_SYSTEM_TEST_REVERB_INDEX_ROUTE))
        ->assertOk()
        ->assertSee('TestReverb')
        ->assertSee('system.reverb-test.'.$user->id)
        ->assertSee('Dispatch 3 turns (30 events)');
});

it('streams long-lived coding-agent events over sse', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route(FEATURE_SYSTEM_TEST_SSE_STREAM_ROUTE, [
        'duration_seconds' => 2,
        'min_interval_seconds' => 0,
        'max_interval_seconds' => 0,
    ]));

    $content = $response->streamedContent();

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('Connection', 'keep-alive')
        ->assertHeader('X-Accel-Buffering', 'no');

    expect($content)
        ->toContain('retry: 1000')
        ->toContain('event: agent-feed')
        ->toContain('"connection":"sse"')
        ->toContain('"transport":"http2"')
        ->toContain('"runtime_seconds":2')
        ->toContain('"event_type":"turn.started"')
        ->toContain('event: complete');
});

it('dispatches reverb test events on the current users channel', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);
    Event::fake([ReverbTestMessageOccurred::class]);

    $this->postJson(route(FEATURE_SYSTEM_TEST_REVERB_DISPATCH_ROUTE))
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => __('Dispatched :events Reverb coding-agent events across :turns turns.', [
                'events' => TestReverbDispatchController::EVENT_COUNT,
                'turns' => TestReverbDispatchController::TURN_COUNT,
            ]),
        ]);

    $events = Event::dispatched(ReverbTestMessageOccurred::class);

    expect($events)->toHaveCount(TestReverbDispatchController::EVENT_COUNT);

    Event::assertDispatched(ReverbTestMessageOccurred::class, function (ReverbTestMessageOccurred $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->broadcastAs() === ReverbTestMessageOccurred::EVENT_NAME
            && $event->broadcastOn()->name === FEATURE_SYSTEM_REVERB_CHANNEL_PREFIX.$user->id
            && $event->payload['connection'] === 'reverb'
            && $event->payload['transport'] === 'websocket'
            && $event->payload['sequence'] === 1
            && $event->payload['turn_number'] === 1
            && $event->payload['event_type'] === TurnEventType::TurnStarted->value;
    });

    Event::assertDispatched(ReverbTestMessageOccurred::class, function (ReverbTestMessageOccurred $event): bool {
        return $event->payload['turn_number'] === TestReverbDispatchController::TURN_COUNT
            && $event->payload['event_type'] === TurnEventType::TurnCompleted->value;
    });
});
