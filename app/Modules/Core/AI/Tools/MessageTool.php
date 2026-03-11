<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractActionTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;

/**
 * Multi-channel messaging tool for Digital Workers.
 *
 * Provides enterprise-grade messaging across multiple platforms (WhatsApp,
 * Telegram, Slack, Email) via a single deep tool with action-based dispatch.
 * Each action routes through the ChannelAdapterRegistry to the appropriate
 * platform adapter.
 *
 * Supports sending, replying, reacting, editing, deleting messages, creating
 * polls, listing conversations, and searching message history. Channel
 * capabilities are validated before dispatch — unsupported actions return
 * informative errors.
 *
 * Note: Currently returns stub responses. Full channel integration will be
 * implemented once messaging accounts and webhook infrastructure are deployed.
 *
 * Gated by `ai.tool_message.execute` authz capability.
 * Per-channel send capabilities (e.g., `messaging.whatsapp.send`) are
 * enforced at the authz layer, not within this tool.
 */
class MessageTool extends AbstractActionTool
{
    /**
     * Valid actions for messaging.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'send',
        'reply',
        'react',
        'edit',
        'delete',
        'poll',
        'list_conversations',
        'search',
    ];

    /**
     * Maximum text message length.
     */
    private const MAX_TEXT_LENGTH = 50000;

    /**
     * Maximum number of poll options.
     */
    private const MAX_POLL_OPTIONS = 10;

    private readonly MessageToolSupport $support;

    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {
        $this->support = new MessageToolSupport($adapterRegistry);
    }

    public function name(): string
    {
        return 'message';
    }

    public function description(): string
    {
        return 'Send and manage messages across channels (WhatsApp, Telegram, Slack, Email). '
            .'Supports sending text/media, replying, reacting, editing, deleting messages, '
            .'creating polls, listing conversations, and searching message history. '
            .'Each action requires a channel parameter to route to the correct platform.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::MESSAGING;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::MESSAGING;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_message.execute';
    }

    protected function actions(): array
    {
        return self::ACTIONS;
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('channel', 'Channel to use: whatsapp, telegram, slack, email.')->required()
            ->string('target', 'Recipient identifier (phone number, chat ID, email address, channel name).')
            ->string('text', 'Message text content (max '.self::MAX_TEXT_LENGTH.' characters).')
            ->string('media_path', 'Path to media file to attach (for "send" action).')
            ->string('message_id', 'Platform-specific message ID (for reply, react, edit, delete actions).')
            ->string('emoji', 'Emoji to react with (for "react" action).')
            ->string('question', 'Poll question (for "poll" action).')
            ->array('options', 'Poll options (for "poll" action, max '.self::MAX_POLL_OPTIONS.').')
            ->string('query', 'Search query (for "search" action).')
            ->integer('limit', 'Maximum results to return (for list_conversations and search, default 10).');
    }

    protected function handleAction(string $action, array $arguments): ToolResult
    {
        $channel = $this->requireString($arguments, 'channel');

        if (! $this->adapterRegistry->isAvailable($channel)) {
            $available = $this->adapterRegistry->channels();

            return ToolResult::error(
                'Channel "'.$channel.'" is not available. '
                    .($available !== [] ? 'Available channels: '.implode(', ', $available).'.' : 'No channels are configured.'),
                'channel_unavailable',
            );
        }

        return match ($action) {
            'send' => $this->handleSend($channel, $arguments),
            'reply' => $this->handleReply($channel, $arguments),
            'react' => $this->handleReact($channel, $arguments),
            'edit' => $this->handleEdit($channel, $arguments),
            'delete' => $this->handleDelete($channel, $arguments),
            'poll' => $this->handlePoll($channel, $arguments),
            'list_conversations' => $this->handleListConversations($channel, $arguments),
            'search' => $this->handleSearch($channel, $arguments),
        };
    }

    /**
     * Handle the "send" action.
     *
     * Sends a text or media message to a target recipient.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleSend(string $channel, array $arguments): ToolResult
    {
        $target = $this->requireString($arguments, 'target');
        $text = $this->requireString($arguments, 'text');
        $this->support->assertTextLength($text, self::MAX_TEXT_LENGTH);

        $capabilities = $this->support->channelCapabilities($channel);

        if (mb_strlen($text) > $capabilities->maxMessageLength) {
            return ToolResult::error(
                'Message exceeds '.$channel.' limit of '.$capabilities->maxMessageLength.' characters.',
                'message_too_long',
            );
        }

        return $this->encodeResponse([
            'action' => 'send',
            'channel' => $channel,
            'target' => $target,
            'text' => $text,
            'media_path' => $this->optionalString($arguments, 'media_path'),
            'status' => 'sent',
            'message_id' => null,
            'message' => 'Message sent (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "reply" action.
     *
     * Replies to a specific message by its platform message ID.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleReply(string $channel, array $arguments): ToolResult
    {
        $text = $this->requireString($arguments, 'text');
        $this->support->assertTextLength($text, self::MAX_TEXT_LENGTH);

        return $this->encodeResponse([
            'action' => 'reply',
            'channel' => $channel,
            'message_id' => $this->requireString($arguments, 'message_id'),
            'text' => $text,
            'status' => 'replied',
            'message' => 'Reply sent (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "react" action.
     *
     * Reacts to a message with an emoji.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleReact(string $channel, array $arguments): ToolResult
    {
        $this->support->assertCapability($channel, 'supportsReactions', $channel.' does not support reactions.');

        return $this->encodeResponse([
            'action' => 'react',
            'channel' => $channel,
            'message_id' => $this->requireString($arguments, 'message_id'),
            'emoji' => $this->requireString($arguments, 'emoji'),
            'status' => 'reacted',
            'message' => 'Reaction added (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "edit" action.
     *
     * Edits a previously sent message.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleEdit(string $channel, array $arguments): ToolResult
    {
        $this->support->assertCapability($channel, 'supportsEditing', $channel.' does not support message editing.');

        $text = $this->requireString($arguments, 'text');
        $this->support->assertTextLength($text, self::MAX_TEXT_LENGTH);

        return $this->encodeResponse([
            'action' => 'edit',
            'channel' => $channel,
            'message_id' => $this->requireString($arguments, 'message_id'),
            'text' => $text,
            'status' => 'edited',
            'message' => 'Message edited (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "delete" action.
     *
     * Deletes a previously sent message.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleDelete(string $channel, array $arguments): ToolResult
    {
        $this->support->assertCapability($channel, 'supportsDeletion', $channel.' does not support message deletion.');

        return $this->encodeResponse([
            'action' => 'delete',
            'channel' => $channel,
            'message_id' => $this->requireString($arguments, 'message_id'),
            'status' => 'deleted',
            'message' => 'Message deleted (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "poll" action.
     *
     * Creates a poll in the target conversation.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handlePoll(string $channel, array $arguments): ToolResult
    {
        $this->support->assertCapability($channel, 'supportsPolls', $channel.' does not support polls.');

        return $this->encodeResponse([
            'action' => 'poll',
            'channel' => $channel,
            'target' => $this->requireString($arguments, 'target'),
            'question' => $this->requireString($arguments, 'question'),
            'options' => $this->support->validatedPollOptions($arguments['options'] ?? [], self::MAX_POLL_OPTIONS),
            'status' => 'created',
            'message' => 'Poll created (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "list_conversations" action.
     *
     * Lists recent conversations on the specified channel.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleListConversations(string $channel, array $arguments): ToolResult
    {
        return $this->encodeResponse([
            'action' => 'list_conversations',
            'channel' => $channel,
            'limit' => $this->optionalInt($arguments, 'limit', 10, min: 1, max: 50),
            'conversations' => [],
            'status' => 'listed',
            'message' => 'Conversations listed (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * Handle the "search" action.
     *
     * Searches message history across the specified channel.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleSearch(string $channel, array $arguments): ToolResult
    {
        $this->support->assertCapability($channel, 'supportsSearch', $channel.' does not support message search.');

        return $this->encodeResponse([
            'action' => 'search',
            'channel' => $channel,
            'query' => $this->requireString($arguments, 'query'),
            'limit' => $this->optionalInt($arguments, 'limit', 10, min: 1, max: 50),
            'results' => [],
            'status' => 'searched',
            'message' => 'Search completed (stub). Channel adapter integration pending.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeResponse(array $payload): ToolResult
    {
        return ToolResult::success(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

/**
 * Internal helper for MessageTool validation and channel capability checks.
 *
 * Throws ToolArgumentException for all validation failures so they are
 * caught by AbstractTool::execute() and formatted as ToolResult::error().
 */
final class MessageToolSupport
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * @throws ToolArgumentException If the text exceeds the maximum length
     */
    public function assertTextLength(string $text, int $maxTextLength): void
    {
        if (mb_strlen($text) > $maxTextLength) {
            throw new ToolArgumentException('"text" must not exceed '.$maxTextLength.' characters.');
        }
    }

    public function channelCapabilities(string $channel): ChannelCapabilities
    {
        return $this->resolveAdapter($channel)->capabilities();
    }

    /**
     * @throws ToolArgumentException If the channel does not support the capability
     */
    public function assertCapability(string $channel, string $capabilityProperty, string $errorMessage): void
    {
        if (! $this->channelCapabilities($channel)->{$capabilityProperty}) {
            throw new ToolArgumentException($errorMessage);
        }
    }

    /**
     * @return list<string>
     *
     * @throws ToolArgumentException If options are invalid
     */
    public function validatedPollOptions(mixed $options, int $maxPollOptions): array
    {
        if (! is_array($options) || count($options) < 2) {
            throw new ToolArgumentException('"options" must be an array with at least 2 items.');
        }

        if (count($options) > $maxPollOptions) {
            throw new ToolArgumentException('"options" must not exceed '.$maxPollOptions.' items.');
        }

        $normalized = [];

        foreach ($options as $option) {
            if (! is_string($option) || trim($option) === '') {
                throw new ToolArgumentException('Each poll option must be a non-empty string.');
            }

            $normalized[] = trim($option);
        }

        return $normalized;
    }

    /**
     * @throws ToolArgumentException If the channel adapter is not registered
     */
    private function resolveAdapter(string $channel): ChannelAdapter
    {
        return $this->adapterRegistry->resolve($channel)
            ?? throw new ToolArgumentException('Channel "'.$channel.'" is not registered.');
    }
}
