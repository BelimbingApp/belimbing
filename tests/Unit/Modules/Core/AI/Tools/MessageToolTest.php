<?php

use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Modules\Core\AI\Tools\MessageTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const UPDATED_MESSAGE_TEXT = 'Updated text';
const TELEGRAM_TARGET = '+1234567890';
const EMAIL_TARGET = 'user@example.com';
const LUNCH_QUESTION = 'Lunch?';

dataset('message actions requiring message_id', [
    ['reply', ['text' => 'Reply text']],
    ['react', ['emoji' => '👍']],
    ['edit', ['text' => UPDATED_MESSAGE_TEXT]],
    ['delete', []],
]);

dataset('message actions requiring text', [
    ['reply', ['message_id' => 'msg-123']],
    ['edit', ['message_id' => 'msg-123']],
]);

beforeEach(function () {
    $this->registry = new ChannelAdapterRegistry;

    // Register a full-capability adapter (Telegram-like)
    $fullAdapter = Mockery::mock(ChannelAdapter::class);
    $fullAdapter->shouldReceive('channelId')->andReturn('telegram');
    $fullAdapter->shouldReceive('label')->andReturn('Telegram');
    $fullAdapter->shouldReceive('capabilities')->andReturn(new ChannelCapabilities(
        supportsReactions: true,
        supportsEditing: true,
        supportsDeletion: true,
        supportsPolls: true,
        supportsThreads: true,
        supportsMedia: true,
        supportsSearch: true,
        maxMessageLength: 4096,
    ));
    $this->registry->register($fullAdapter);

    // Register a limited-capability adapter (Email-like)
    $limitedAdapter = Mockery::mock(ChannelAdapter::class);
    $limitedAdapter->shouldReceive('channelId')->andReturn('email');
    $limitedAdapter->shouldReceive('label')->andReturn('Email');
    $limitedAdapter->shouldReceive('capabilities')->andReturn(new ChannelCapabilities(
        supportsReactions: false,
        supportsEditing: false,
        supportsDeletion: false,
        supportsPolls: false,
        supportsMedia: true,
        supportsSearch: true,
        maxMessageLength: 100000,
    ));
    $this->registry->register($limitedAdapter);

    $this->tool = new MessageTool($this->registry);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'message',
            'ai.tool_message.execute',
            ['action', 'channel'],
            ['action', 'channel'],
        );
    });

    it('schema declares all actions', function () {
        $schema = $this->tool->parametersSchema();
        $actions = $schema['properties']['action']['enum'];

        expect($actions)->toContain('send')
            ->and($actions)->toContain('reply')
            ->and($actions)->toContain('react')
            ->and($actions)->toContain('edit')
            ->and($actions)->toContain('delete')
            ->and($actions)->toContain('poll')
            ->and($actions)->toContain('list_conversations')
            ->and($actions)->toContain('search');
    });
});

describe('input validation', function () {
    it('rejects missing action', function () {
        $this->assertToolError(['channel' => 'telegram']);
    });

    it('rejects invalid action', function () {
        $this->assertToolError(['action' => 'bogus', 'channel' => 'telegram'], 'must be one of');
    });

    it('rejects missing channel', function () {
        $this->assertToolError(['action' => 'send'], 'channel');
    });

    it('rejects empty channel', function () {
        $this->assertToolError(['action' => 'send', 'channel' => ''], 'channel');
    });

    it('rejects unavailable channel', function () {
        $this->assertToolError(['action' => 'send', 'channel' => 'discord'], 'not available');
    });

    it('lists available channels when channel unavailable', function () {
        $result = $this->tool->execute(['action' => 'send', 'channel' => 'discord']);
        expect((string) $result)->toContain('telegram')
            ->and((string) $result)->toContain('email');
    });

    it('handles no registered channels gracefully', function () {
        $emptyRegistry = new ChannelAdapterRegistry;
        $tool = new MessageTool($emptyRegistry);

        $result = $tool->execute(['action' => 'send', 'channel' => 'whatsapp']);
        expect((string) $result)->toContain('No channels are configured');
    });
});

describe('send action', function () {
    it('requires target', function () {
        $this->assertToolError([
            'action' => 'send',
            'channel' => 'telegram',
            'text' => 'Hello',
        ], 'target');
    });

    it('requires text', function () {
        $this->assertToolError([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => TELEGRAM_TARGET,
        ], 'text');
    });

    it('rejects text exceeding max length', function () {
        $result = $this->tool->execute([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => TELEGRAM_TARGET,
            'text' => str_repeat('x', 50001),
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('50000');
    });

    it('rejects text exceeding channel limit', function () {
        $result = $this->tool->execute([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => TELEGRAM_TARGET,
            'text' => str_repeat('x', 4097),
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('4096');
    });

    it('sends successfully', function () {
        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => '+1234567890',
            'text' => 'Hello there!',
        ]);

        expect($data['action'])->toBe('send')
            ->and($data['channel'])->toBe('telegram')
            ->and($data['target'])->toBe(TELEGRAM_TARGET)
            ->and($data['text'])->toBe('Hello there!')
            ->and($data['status'])->toBe('sent');
    });

    it('includes media_path when provided', function () {
        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => TELEGRAM_TARGET,
            'text' => 'See attachment',
            'media_path' => '/storage/uploads/image.png',
        ]);
        expect($data['media_path'])->toBe('/storage/uploads/image.png');
    });

    it('sets media_path to null when not provided', function () {
        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => 'telegram',
            'target' => TELEGRAM_TARGET,
            'text' => 'No attachment',
        ]);
        expect($data['media_path'])->toBeNull();
    });

    it('allows longer text on high-limit channels', function () {
        $longText = str_repeat('x', 5000);
        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => 'email',
            'target' => EMAIL_TARGET,
            'text' => $longText,
        ]);
        expect($data['status'])->toBe('sent');
    });
});

describe('reply action', function () {
    it('replies successfully', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'reply',
            'channel' => 'telegram',
            'message_id' => 'msg-123',
            'text' => 'Got it!',
        ], 'replied');

        expect($data['action'])->toBe('reply')
            ->and($data['channel'])->toBe('telegram')
            ->and($data['message_id'])->toBe('msg-123')
            ->and($data['text'])->toBe('Got it!');
    });
});

describe('react action', function () {
    it('requires emoji', function () {
        $this->assertToolError([
            'action' => 'react',
            'channel' => 'telegram',
            'message_id' => 'msg-123',
        ], 'emoji');
    });

    it('reacts successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'react',
            'channel' => 'telegram',
            'message_id' => 'msg-123',
            'emoji' => '👍',
        ], 'reacted');

        expect($data['action'])->toBe('react')
            ->and($data['emoji'])->toBe('👍');
    });

    it('rejects reaction on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'react',
            'channel' => 'email',
            'message_id' => 'msg-123',
            'emoji' => '👍',
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support reactions');
    });
});

describe('edit action', function () {
    it('edits successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'edit',
            'channel' => 'telegram',
            'message_id' => 'msg-123',
            'text' => UPDATED_MESSAGE_TEXT,
        ], 'edited');

        expect($data['action'])->toBe('edit')
            ->and($data['message_id'])->toBe('msg-123')
            ->and($data['text'])->toBe(UPDATED_MESSAGE_TEXT);
    });

    it('rejects editing on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'edit',
            'channel' => 'email',
            'message_id' => 'msg-123',
            'text' => UPDATED_MESSAGE_TEXT,
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support message editing');
    });
});

describe('delete action', function () {
    it('deletes successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'delete',
            'channel' => 'telegram',
            'message_id' => 'msg-123',
        ], 'deleted');

        expect($data['action'])->toBe('delete')
            ->and($data['message_id'])->toBe('msg-123');
    });

    it('rejects deletion on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'delete',
            'channel' => 'email',
            'message_id' => 'msg-123',
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support message deletion');
    });
});

describe('poll action', function () {
    it('requires target', function () {
        $this->assertToolError([
            'action' => 'poll',
            'channel' => 'telegram',
            'question' => LUNCH_QUESTION,
            'options' => ['Pizza', 'Sushi'],
        ], 'target');
    });

    it('requires question', function () {
        $this->assertToolError([
            'action' => 'poll',
            'channel' => 'telegram',
            'target' => 'chat-123',
            'options' => ['Pizza', 'Sushi'],
        ], 'question');
    });

    it('requires at least 2 options', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => 'telegram',
            'target' => 'chat-123',
            'question' => LUNCH_QUESTION,
            'options' => ['Pizza'],
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('at least 2');
    });

    it('rejects more than 10 options', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => 'telegram',
            'target' => 'chat-123',
            'question' => 'Pick one?',
            'options' => array_fill(0, 11, 'Option'),
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('10');
    });

    it('rejects empty option strings', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => 'telegram',
            'target' => 'chat-123',
            'question' => LUNCH_QUESTION,
            'options' => ['Pizza', ''],
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('non-empty string');
    });

    it('creates poll successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'poll',
            'channel' => 'telegram',
            'target' => 'chat-123',
            'question' => LUNCH_QUESTION,
            'options' => ['Pizza', 'Sushi', 'Tacos'],
        ], 'created');

        expect($data['action'])->toBe('poll')
            ->and($data['channel'])->toBe('telegram')
            ->and($data['question'])->toBe(LUNCH_QUESTION)
            ->and($data['options'])->toBe(['Pizza', 'Sushi', 'Tacos']);
    });

    it('rejects polls on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => 'email',
            'target' => EMAIL_TARGET,
            'question' => LUNCH_QUESTION,
            'options' => ['Pizza', 'Sushi'],
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support polls');
    });
});

describe('list_conversations action', function () {
    it('lists conversations with default limit', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => 'telegram',
        ]);

        expect($data['action'])->toBe('list_conversations')
            ->and($data['channel'])->toBe('telegram')
            ->and($data['limit'])->toBe(10)
            ->and($data['conversations'])->toBe([])
            ->and($data['status'])->toBe('listed');
    });

    it('respects custom limit', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => 'telegram',
            'limit' => 25,
        ]);
        expect($data['limit'])->toBe(25);
    });

    it('caps limit at 50', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => 'telegram',
            'limit' => 100,
        ]);
        expect($data['limit'])->toBe(50);
    });

    it('enforces minimum limit of 1', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => 'telegram',
            'limit' => 0,
        ]);
        expect($data['limit'])->toBe(1);
    });
});

describe('search action', function () {
    it('requires query', function () {
        $this->assertToolError([
            'action' => 'search',
            'channel' => 'telegram',
        ], 'query');
    });

    it('searches successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'search',
            'channel' => 'telegram',
            'query' => 'project status',
        ], 'searched');

        expect($data['action'])->toBe('search')
            ->and($data['channel'])->toBe('telegram')
            ->and($data['query'])->toBe('project status')
            ->and($data['limit'])->toBe(10)
            ->and($data['results'])->toBe([]);
    });

    it('respects custom limit', function () {
        $data = $this->decodeToolExecution([
            'action' => 'search',
            'channel' => 'telegram',
            'query' => 'meeting',
            'limit' => 5,
        ]);
        expect($data['limit'])->toBe(5);
    });

    it('rejects search on unsupported channel', function () {
        // Need a channel without search support
        $noSearchAdapter = Mockery::mock(ChannelAdapter::class);
        $noSearchAdapter->shouldReceive('channelId')->andReturn('nosearch');
        $noSearchAdapter->shouldReceive('capabilities')->andReturn(new ChannelCapabilities(
            supportsSearch: false,
        ));
        $this->registry->register($noSearchAdapter);

        $result = $this->tool->execute([
            'action' => 'search',
            'channel' => 'nosearch',
            'query' => 'test',
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support message search');
    });
});

describe('shared action validation', function () {
    it('requires message_id for relevant actions', function (string $action, array $arguments) {
        $this->assertToolError([
            'action' => $action,
            'channel' => 'telegram',
            ...$arguments,
        ], 'message_id');
    })->with('message actions requiring message_id');

    it('requires text for relevant actions', function (string $action, array $arguments) {
        $this->assertToolError([
            'action' => $action,
            'channel' => 'telegram',
            ...$arguments,
        ], 'text');
    })->with('message actions requiring text');
});

describe('channel adapter registry integration', function () {
    it('lists available channels in error messages', function () {
        $result = $this->tool->execute([
            'action' => 'send',
            'channel' => 'unknown',
        ]);
        expect((string) $result)->toContain('telegram')
            ->and((string) $result)->toContain('email');
    });

    it('routes to correct channel', function () {
        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => 'email',
            'target' => EMAIL_TARGET,
            'text' => 'Hello via email',
        ]);
        expect($data['channel'])->toBe('email');
    });
});
