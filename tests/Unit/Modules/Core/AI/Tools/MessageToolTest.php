<?php

use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Modules\Core\AI\Services\Messaging\OutboundMessageService;
use App\Modules\Core\AI\Services\Messaging\OutboundSendResult;
use App\Modules\Core\AI\Tools\MessageTool;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const MSG_TOOL_UPDATED_TEXT = 'Updated text';
const MSG_TOOL_TELEGRAM_TARGET = '+1234567890';
const MSG_TOOL_EMAIL_TARGET = 'user@example.com';
const MSG_TOOL_LUNCH_QUESTION = 'Lunch?';
const MSG_TOOL_CHANNEL_TELEGRAM = 'telegram';
const MSG_TOOL_CHANNEL_EMAIL = 'email';
const MSG_TOOL_MSG_ID = 'msg-123';

dataset('message actions requiring message_id', [
    ['reply', ['text' => 'Reply text']],
    ['react', ['emoji' => '👍']],
    ['edit', ['text' => MSG_TOOL_UPDATED_TEXT]],
    ['delete', []],
]);

dataset('message actions requiring text', [
    ['reply', ['message_id' => MSG_TOOL_MSG_ID]],
    ['edit', ['message_id' => MSG_TOOL_MSG_ID]],
]);

function makeOutboundServiceMock(): OutboundMessageService
{
    return Mockery::mock(OutboundMessageService::class);
}

function makeInactiveExecutionContext(): AgentExecutionContext
{
    return new AgentExecutionContext;
}

function makeSendResult(bool $success = true, ?string $messageId = 'ext-msg-1'): OutboundSendResult
{
    return new OutboundSendResult(
        success: $success,
        messageId: $messageId,
        conversationId: 42,
        messageRecordId: 100,
    );
}

/**
 * Create a mock authenticated user with company context and set as current user.
 *
 * Uses an anonymous Authenticatable class instead of Mockery because:
 * 1. Mockery mocks fail PHP 8.5 native type checks in SessionGuard::setUser()
 * 2. method_exists() returns false for Mockery's __call magic methods
 */
function actAsUserWithCompany(int $companyId = 10): void
{
    $user = new class($companyId) implements Authenticatable
    {
        public function __construct(private readonly int $companyId) {}

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthPassword(): string
        {
            return 'password';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }

        public function getCompanyId(): int
        {
            return $this->companyId;
        }
    };

    app('auth')->guard()->setUser($user);
}

function makeFullCapabilities(): ChannelCapabilities
{
    return new ChannelCapabilities(
        supportsReactions: true,
        supportsEditing: true,
        supportsDeletion: true,
        supportsPolls: true,
        supportsThreads: true,
        supportsMedia: true,
        supportsSearch: true,
        maxMessageLength: 4096,
    );
}

function makeLimitedCapabilities(): ChannelCapabilities
{
    return new ChannelCapabilities(
        supportsReactions: false,
        supportsEditing: false,
        supportsDeletion: false,
        supportsPolls: false,
        supportsMedia: true,
        supportsSearch: true,
        maxMessageLength: 100000,
    );
}

beforeEach(function () {
    $this->registry = new ChannelAdapterRegistry;

    // Register a full-capability adapter (Telegram-like)
    $fullAdapter = Mockery::mock(ChannelAdapter::class);
    $fullAdapter->shouldReceive('channelId')->andReturn(MSG_TOOL_CHANNEL_TELEGRAM);
    $fullAdapter->shouldReceive('label')->andReturn('Telegram');
    $fullAdapter->shouldReceive('capabilities')->andReturn(makeFullCapabilities());
    $this->registry->register($fullAdapter);

    // Register a limited-capability adapter (Email-like)
    $limitedAdapter = Mockery::mock(ChannelAdapter::class);
    $limitedAdapter->shouldReceive('channelId')->andReturn(MSG_TOOL_CHANNEL_EMAIL);
    $limitedAdapter->shouldReceive('label')->andReturn('Email');
    $limitedAdapter->shouldReceive('capabilities')->andReturn(makeLimitedCapabilities());
    $this->registry->register($limitedAdapter);

    $this->outboundService = makeOutboundServiceMock();
    $this->executionContext = makeInactiveExecutionContext();
    $this->tool = new MessageTool($this->registry, $this->outboundService, $this->executionContext);
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
        $this->assertToolError(['channel' => MSG_TOOL_CHANNEL_TELEGRAM]);
    });

    it('rejects invalid action', function () {
        $this->assertToolError(['action' => 'bogus', 'channel' => MSG_TOOL_CHANNEL_TELEGRAM], 'must be one of');
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
        expect((string) $result)->toContain(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and((string) $result)->toContain(MSG_TOOL_CHANNEL_EMAIL);
    });

    it('handles no registered channels gracefully', function () {
        $emptyRegistry = new ChannelAdapterRegistry;
        $tool = new MessageTool($emptyRegistry, $this->outboundService, $this->executionContext);

        $result = $tool->execute(['action' => 'send', 'channel' => 'whatsapp']);
        expect((string) $result)->toContain('No channels are configured');
    });
});

describe('send action', function () {
    it('requires target', function () {
        $this->assertToolError([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'text' => 'Hello',
        ], 'target');
    });

    it('requires text', function () {
        $this->assertToolError([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => MSG_TOOL_TELEGRAM_TARGET,
        ], 'text');
    });

    it('rejects text exceeding max length', function () {
        $result = $this->tool->execute([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => MSG_TOOL_TELEGRAM_TARGET,
            'text' => str_repeat('x', 50001),
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('50000');
    });

    it('sends successfully via outbound service', function () {
        actAsUserWithCompany();

        $this->outboundService->shouldReceive('send')
            ->once()
            ->andReturn(makeSendResult());

        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => MSG_TOOL_TELEGRAM_TARGET,
            'text' => 'Hello there!',
        ]);

        expect($data['action'])->toBe('send')
            ->and($data['channel'])->toBe(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and($data['target'])->toBe(MSG_TOOL_TELEGRAM_TARGET)
            ->and($data['status'])->toBe('sent');
    });

    it('returns error when company context is unavailable', function () {
        // executionContext already returns active=false, and no auth user
        $result = $this->tool->execute([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => MSG_TOOL_TELEGRAM_TARGET,
            'text' => 'Hello',
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('company context');
    });

    it('sends via email channel through outbound service', function () {
        actAsUserWithCompany();

        $this->outboundService->shouldReceive('send')
            ->once()
            ->andReturn(makeSendResult());

        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'target' => MSG_TOOL_EMAIL_TARGET,
            'text' => str_repeat('x', 5000),
        ]);
        expect($data['status'])->toBe('sent');
    });
});

describe('reply action', function () {
    it('replies successfully via outbound service', function () {
        actAsUserWithCompany();

        $this->outboundService->shouldReceive('reply')
            ->once()
            ->andReturn(makeSendResult());

        $data = $this->decodeToolExecution([
            'action' => 'reply',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'message_id' => MSG_TOOL_MSG_ID,
            'text' => 'Got it!',
        ]);

        expect($data['action'])->toBe('reply')
            ->and($data['channel'])->toBe(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and($data['status'])->toBe('sent');
    });
});

describe('react action', function () {
    it('requires emoji', function () {
        $this->assertToolError([
            'action' => 'react',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'message_id' => MSG_TOOL_MSG_ID,
        ], 'emoji');
    });

    it('reacts successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'react',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'message_id' => MSG_TOOL_MSG_ID,
            'emoji' => '👍',
        ], 'reacted');

        expect($data['action'])->toBe('react')
            ->and($data['emoji'])->toBe('👍');
    });

    it('rejects reaction on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'react',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'message_id' => MSG_TOOL_MSG_ID,
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
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'message_id' => MSG_TOOL_MSG_ID,
            'text' => MSG_TOOL_UPDATED_TEXT,
        ], 'edited');

        expect($data['action'])->toBe('edit')
            ->and($data['message_id'])->toBe(MSG_TOOL_MSG_ID)
            ->and($data['text'])->toBe(MSG_TOOL_UPDATED_TEXT);
    });

    it('rejects editing on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'edit',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'message_id' => MSG_TOOL_MSG_ID,
            'text' => MSG_TOOL_UPDATED_TEXT,
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support message editing');
    });
});

describe('delete action', function () {
    it('deletes successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'delete',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'message_id' => MSG_TOOL_MSG_ID,
        ], 'deleted');

        expect($data['action'])->toBe('delete')
            ->and($data['message_id'])->toBe(MSG_TOOL_MSG_ID);
    });

    it('rejects deletion on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'delete',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'message_id' => MSG_TOOL_MSG_ID,
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('does not support message deletion');
    });
});

describe('poll action', function () {
    it('requires target', function () {
        $this->assertToolError([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'question' => MSG_TOOL_LUNCH_QUESTION,
            'options' => ['Pizza', 'Sushi'],
        ], 'target');
    });

    it('requires question', function () {
        $this->assertToolError([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => 'chat-123',
            'options' => ['Pizza', 'Sushi'],
        ], 'question');
    });

    it('requires at least 2 options', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => 'chat-123',
            'question' => MSG_TOOL_LUNCH_QUESTION,
            'options' => ['Pizza'],
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('at least 2');
    });

    it('rejects more than 10 options', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
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
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => 'chat-123',
            'question' => MSG_TOOL_LUNCH_QUESTION,
            'options' => ['Pizza', ''],
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('non-empty string');
    });

    it('creates poll successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'target' => 'chat-123',
            'question' => MSG_TOOL_LUNCH_QUESTION,
            'options' => ['Pizza', 'Sushi', 'Tacos'],
        ], 'created');

        expect($data['action'])->toBe('poll')
            ->and($data['channel'])->toBe(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and($data['question'])->toBe(MSG_TOOL_LUNCH_QUESTION)
            ->and($data['options'])->toBe(['Pizza', 'Sushi', 'Tacos']);
    });

    it('rejects polls on unsupported channel', function () {
        $result = $this->tool->execute([
            'action' => 'poll',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'target' => MSG_TOOL_EMAIL_TARGET,
            'question' => MSG_TOOL_LUNCH_QUESTION,
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
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
        ]);

        expect($data['action'])->toBe('list_conversations')
            ->and($data['channel'])->toBe(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and($data['limit'])->toBe(10)
            ->and($data['conversations'])->toBe([])
            ->and($data['status'])->toBe('listed');
    });

    it('respects custom limit', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'limit' => 25,
        ]);
        expect($data['limit'])->toBe(25);
    });

    it('caps limit at 50', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'limit' => 100,
        ]);
        expect($data['limit'])->toBe(50);
    });

    it('enforces minimum limit of 1', function () {
        $data = $this->decodeToolExecution([
            'action' => 'list_conversations',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'limit' => 0,
        ]);
        expect($data['limit'])->toBe(1);
    });
});

describe('search action', function () {
    it('requires query', function () {
        $this->assertToolError([
            'action' => 'search',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
        ], 'query');
    });

    it('searches successfully on supported channel', function () {
        $data = $this->assertToolExecutionStatus([
            'action' => 'search',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'query' => 'project status',
        ], 'searched');

        expect($data['action'])->toBe('search')
            ->and($data['channel'])->toBe(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and($data['query'])->toBe('project status')
            ->and($data['limit'])->toBe(10)
            ->and($data['results'])->toBe([]);
    });

    it('respects custom limit', function () {
        $data = $this->decodeToolExecution([
            'action' => 'search',
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            'query' => 'meeting',
            'limit' => 5,
        ]);
        expect($data['limit'])->toBe(5);
    });

    it('rejects search on unsupported channel', function () {
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
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
            ...$arguments,
        ], 'message_id');
    })->with('message actions requiring message_id');

    it('requires text for relevant actions', function (string $action, array $arguments) {
        $this->assertToolError([
            'action' => $action,
            'channel' => MSG_TOOL_CHANNEL_TELEGRAM,
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
        expect((string) $result)->toContain(MSG_TOOL_CHANNEL_TELEGRAM)
            ->and((string) $result)->toContain(MSG_TOOL_CHANNEL_EMAIL);
    });

    it('routes to correct channel via outbound service', function () {
        actAsUserWithCompany();

        $this->outboundService->shouldReceive('send')
            ->once()
            ->andReturn(makeSendResult());

        $data = $this->decodeToolExecution([
            'action' => 'send',
            'channel' => MSG_TOOL_CHANNEL_EMAIL,
            'target' => MSG_TOOL_EMAIL_TARGET,
            'text' => 'Hello via email',
        ]);
        expect($data['channel'])->toBe(MSG_TOOL_CHANNEL_EMAIL);
    });
});
