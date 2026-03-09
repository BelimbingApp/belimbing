<?php

use App\Modules\Core\AI\Tools\NotificationTool;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Support\AssertsToolBehavior;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

dataset('notification required string fields', [
    [['user_id' => 1, 'body' => 'Test body'], 'subject'],
    [['user_id' => 1, 'subject' => '', 'body' => 'Test body'], 'subject'],
    [['user_id' => 1, 'subject' => 'Test'], 'body'],
    [['user_id' => 1, 'subject' => 'Test', 'body' => ' '], 'body'],
]);

beforeEach(function () {
    $this->tool = new NotificationTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'notification',
            'ai.tool_notification.execute',
            ['user_id', 'channel', 'subject', 'body'],
            ['user_id', 'subject', 'body'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing user_id', function () {
        $this->assertToolError(['subject' => 'Test', 'body' => 'Test body']);
    });

    it('rejects invalid user_id type', function () {
        $result = $this->tool->execute(['user_id' => 'invalid', 'subject' => 'Test', 'body' => 'Test body']);
        expect($result)->toContain('Error');
    });

    it('rejects negative user_id', function () {
        $result = $this->tool->execute(['user_id' => -1, 'subject' => 'Test', 'body' => 'Test body']);
        expect($result)->toContain('Error');
    });

    it('rejects missing or empty required string fields', function (array $arguments, string $fragment) {
        $this->assertToolError($arguments, $fragment);
    })->with('notification required string fields');

    it('rejects subject exceeding max length', function () {
        $result = $this->tool->execute([
            'user_id' => 1,
            'subject' => str_repeat('x', 256),
            'body' => 'Test body',
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('exceed');
    });

    it('rejects body exceeding max length', function () {
        $result = $this->tool->execute([
            'user_id' => 1,
            'subject' => 'Test',
            'body' => str_repeat('x', 5001),
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('exceed');
    });

    it('rejects invalid channel', function () {
        $result = $this->tool->execute([
            'user_id' => 1,
            'subject' => 'Test',
            'body' => 'Test body',
            'channel' => 'sms',
        ]);
        expect($result)->toContain('Error');
    });

    it('defaults channel to database', function () {
        $schema = $this->tool->parametersSchema();

        expect($schema['properties']['channel']['enum'])->toContain('database');
    });
});

describe('recipient resolution', function () {
    it('returns error for non-existent user_id', function () {
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => 99999,
            'subject' => 'Test',
            'body' => 'Test body',
        ]);

        expect($result)->toContain('Error')
            ->and($result)->toContain('not found');
    });

    it('sends notification to specific user', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'subject' => 'Hello',
            'body' => 'World',
        ]);

        $data = $this->decodeToolResult($result);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('sent')
            ->and($data['recipients'])->toBe(1);
    });

    it('returns error for all when not authenticated', function () {
        $result = $this->tool->execute([
            'user_id' => 'all',
            'subject' => 'Broadcast',
            'body' => 'Message to everyone',
        ]);

        expect($result)->toContain('Error');
    });
});

describe('notification sending', function () {
    it('sends via database channel', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'channel' => 'database',
            'subject' => 'DB Notification',
            'body' => 'Stored in database',
        ]);

        $notifications = Notification::sentNotifications();
        $userKey = get_class($user);
        expect($notifications[$userKey][$user->getKey()] ?? [])->not->toBeEmpty();

        $sentTypes = $notifications[$userKey][$user->getKey()];
        $firstType = array_key_first($sentTypes);
        $notification = $sentTypes[$firstType][0]['notification'];
        expect($notification->via($user))->toBe(['database']);

        $data = $this->decodeToolResult($result);
        expect($data['channel'])->toBe('database');
    });

    it('sends via broadcast channel', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'channel' => 'broadcast',
            'subject' => 'Broadcast Notification',
            'body' => 'Sent via broadcast',
        ]);

        $notifications = Notification::sentNotifications();
        $userKey = get_class($user);
        $sentTypes = $notifications[$userKey][$user->getKey()];
        $firstType = array_key_first($sentTypes);
        $notification = $sentTypes[$firstType][0]['notification'];
        expect($notification->via($user))->toBe(['broadcast']);

        $data = $this->decodeToolResult($result);
        expect($data['channel'])->toBe('broadcast');
    });

    it('returns success with correct structure', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'subject' => 'Structure Test',
            'body' => 'Checking keys',
        ]);

        $data = json_decode($result, true);

        expect($data)->toHaveKeys(['status', 'recipients', 'channel', 'subject', 'sent_at']);
    });
});

describe('output format', function () {
    it('returns valid JSON on success', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'subject' => 'JSON Test',
            'body' => 'Valid JSON output',
        ]);

        expect($this->decodeToolResult($result))->toBeArray();
    });

    it('includes sent_at timestamp', function () {
        $user = User::factory()->create();
        Notification::fake();

        $result = $this->tool->execute([
            'user_id' => $user->id,
            'subject' => 'Timestamp Test',
            'body' => 'Check sent_at field',
        ]);

        $data = $this->decodeToolResult($result);
        expect($data['sent_at'])->not->toBeEmpty();
    });
});
