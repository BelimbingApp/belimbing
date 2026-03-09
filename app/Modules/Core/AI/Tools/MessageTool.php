<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Modules\Core\AI\Contracts\DigitalWorkerTool;
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
class MessageTool implements DigitalWorkerTool
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

    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {}

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

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => self::ACTIONS,
                    'description' => 'The messaging action to perform.',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel to use: whatsapp, telegram, slack, email.',
                ],
                'target' => [
                    'type' => 'string',
                    'description' => 'Recipient identifier (phone number, chat ID, email address, channel name).',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Message text content (max '.self::MAX_TEXT_LENGTH.' characters).',
                ],
                'media_path' => [
                    'type' => 'string',
                    'description' => 'Path to media file to attach (for "send" action).',
                ],
                'message_id' => [
                    'type' => 'string',
                    'description' => 'Platform-specific message ID (for reply, react, edit, delete actions).',
                ],
                'emoji' => [
                    'type' => 'string',
                    'description' => 'Emoji to react with (for "react" action).',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Poll question (for "poll" action).',
                ],
                'options' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Poll options (for "poll" action, max '.self::MAX_POLL_OPTIONS.').',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (for "search" action).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum results to return (for list_conversations and search, default 10).',
                ],
            ],
            'required' => ['action', 'channel'],
        ];
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_message.execute';
    }

    public function execute(array $arguments): string
    {
        $action = $arguments['action'] ?? '';

        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return 'Error: Invalid action. Must be one of: '.implode(', ', self::ACTIONS).'.';
        }

        $channel = $arguments['channel'] ?? '';

        if (! is_string($channel) || trim($channel) === '') {
            return 'Error: "channel" is required. Available channels: '
                .implode(', ', $this->adapterRegistry->channels()).'.';
        }

        $channel = trim($channel);

        if (! $this->adapterRegistry->isAvailable($channel)) {
            $available = $this->adapterRegistry->channels();

            return 'Error: Channel "'.$channel.'" is not available. '
                .($available !== [] ? 'Available channels: '.implode(', ', $available).'.' : 'No channels are configured.');
        }

        try {
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
        } catch (MessageToolValidationException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Handle the "send" action.
     *
     * Sends a text or media message to a target recipient.
     *
     * @param  string  $channel  Channel identifier
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleSend(string $channel, array $arguments): string
    {
        $target = $this->resolveRequiredStringArgument($arguments, 'target', 'send');
        $text = $this->resolveRequiredTextArgument($arguments, 'send');

        $capabilities = $this->resolveChannelCapabilities($channel);

        if (mb_strlen($text) > $capabilities->maxMessageLength) {
            return 'Error: Message exceeds '.$channel.' limit of '
                .$capabilities->maxMessageLength.' characters.';
        }

        $mediaPath = $arguments['media_path'] ?? null;

        return $this->encodeResponse([
            'action' => 'send',
            'channel' => $channel,
            'target' => $target,
            'text' => $text,
            'media_path' => is_string($mediaPath) ? trim($mediaPath) : null,
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
    private function handleReply(string $channel, array $arguments): string
    {
        $messageId = $this->resolveRequiredStringArgument($arguments, 'message_id', 'reply');
        $text = $this->resolveRequiredTextArgument($arguments, 'reply');

        return $this->encodeResponse([
            'action' => 'reply',
            'channel' => $channel,
            'message_id' => $messageId,
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
    private function handleReact(string $channel, array $arguments): string
    {
        $this->assertChannelCapability($channel, 'supportsReactions', 'Error: '.$channel.' does not support reactions.');

        $messageId = $this->resolveRequiredStringArgument($arguments, 'message_id', 'react');
        $emoji = $this->resolveRequiredStringArgument($arguments, 'emoji', 'react');

        return $this->encodeResponse([
            'action' => 'react',
            'channel' => $channel,
            'message_id' => $messageId,
            'emoji' => $emoji,
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
    private function handleEdit(string $channel, array $arguments): string
    {
        $this->assertChannelCapability($channel, 'supportsEditing', 'Error: '.$channel.' does not support message editing.');

        $messageId = $this->resolveRequiredStringArgument($arguments, 'message_id', 'edit');
        $text = $this->resolveRequiredTextArgument($arguments, 'edit');

        return $this->encodeResponse([
            'action' => 'edit',
            'channel' => $channel,
            'message_id' => $messageId,
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
    private function handleDelete(string $channel, array $arguments): string
    {
        $this->assertChannelCapability($channel, 'supportsDeletion', 'Error: '.$channel.' does not support message deletion.');

        $messageId = $this->resolveRequiredStringArgument($arguments, 'message_id', 'delete');

        return $this->encodeResponse([
            'action' => 'delete',
            'channel' => $channel,
            'message_id' => $messageId,
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
    private function handlePoll(string $channel, array $arguments): string
    {
        $this->assertChannelCapability($channel, 'supportsPolls', 'Error: '.$channel.' does not support polls.');

        $target = $this->resolveRequiredStringArgument($arguments, 'target', 'poll');
        $question = $this->resolveRequiredStringArgument($arguments, 'question', 'poll');

        $options = $arguments['options'] ?? [];

        if (! is_array($options) || count($options) < 2) {
            return 'Error: "options" must be an array with at least 2 items.';
        }

        if (count($options) > self::MAX_POLL_OPTIONS) {
            return 'Error: "options" must not exceed '.self::MAX_POLL_OPTIONS.' items.';
        }

        foreach ($options as $option) {
            if (! is_string($option) || trim($option) === '') {
                return 'Error: Each poll option must be a non-empty string.';
            }
        }

        return $this->encodeResponse([
            'action' => 'poll',
            'channel' => $channel,
            'target' => $target,
            'question' => $question,
            'options' => array_map('trim', $options),
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
    private function handleListConversations(string $channel, array $arguments): string
    {
        $limit = $this->resolveLimit($arguments);

        return $this->encodeResponse([
            'action' => 'list_conversations',
            'channel' => $channel,
            'limit' => $limit,
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
    private function handleSearch(string $channel, array $arguments): string
    {
        $this->assertChannelCapability($channel, 'supportsSearch', 'Error: '.$channel.' does not support message search.');

        $query = $this->resolveRequiredStringArgument($arguments, 'query', 'search');

        $limit = $this->resolveLimit($arguments);

        return $this->encodeResponse([
            'action' => 'search',
            'channel' => $channel,
            'query' => $query,
            'limit' => $limit,
            'results' => [],
            'status' => 'searched',
            'message' => 'Search completed (stub). Channel adapter integration pending.',
        ]);
    }

    private function resolveRequiredStringArgument(array $arguments, string $name, string $action): string
    {
        $value = $arguments[$name] ?? '';

        if (! is_string($value) || trim($value) === '') {
            throw new MessageToolValidationException('Error: "'.$name.'" is required for the '.$action.' action.');
        }

        return trim($value);
    }

    private function resolveRequiredTextArgument(array $arguments, string $action): string
    {
        $text = $this->resolveRequiredStringArgument($arguments, 'text', $action);
        $this->assertTextLength($text);

        return $text;
    }

    private function encodeResponse(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function assertTextLength(string $text): void
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new MessageToolValidationException('Error: "text" must not exceed '.self::MAX_TEXT_LENGTH.' characters.');
        }
    }

    private function assertChannelCapability(string $channel, string $capabilityProperty, string $errorMessage): void
    {
        if (! $this->resolveChannelCapabilities($channel)->{$capabilityProperty}) {
            throw new MessageToolValidationException($errorMessage);
        }
    }

    private function resolveChannelCapabilities(string $channel): ChannelCapabilities
    {
        return $this->resolveAdapter($channel)->capabilities();
    }

    private function resolveAdapter(string $channel): ChannelAdapter
    {
        return $this->adapterRegistry->resolve($channel)
            ?? throw new \RuntimeException('Channel "'.$channel.'" is not registered.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveLimit(array $arguments): int
    {
        $limit = $arguments['limit'] ?? 10;

        return is_int($limit) ? max(1, min(50, $limit)) : 10;
    }
}

final class MessageToolValidationException extends \InvalidArgumentException {}
