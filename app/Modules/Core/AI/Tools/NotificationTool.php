<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\HasToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\User\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Notification sending tool for Agents.
 *
 * Sends notifications to BLB users via Laravel's notification system
 * (database or broadcast channels). Supports sending to a specific user
 * by ID or broadcasting to all users in the authenticated user's company.
 *
 * Gated by `ai.tool_notification.execute` authz capability.
 */
class NotificationTool extends AbstractTool
{
    use HasToolMetadata;

    /**
     * Maximum length for the notification subject.
     */
    private const MAX_SUBJECT_LENGTH = 255;

    /**
     * Maximum length for the notification body.
     */
    private const MAX_BODY_LENGTH = 5000;

    /**
     * Allowed notification channels.
     *
     * @var list<string>
     */
    private const CHANNELS = ['database', 'broadcast'];

    public function name(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return 'Send a notification to a BLB user or all users in the current company. '
            .'Supports database and broadcast channels. '
            .'Use this when the user asks to notify someone, send an alert, '
            .'or broadcast a message to the team.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->oneOf('user_id', 'User ID to notify, or "all" to broadcast to all company users.', [
                ['type' => 'integer', 'description' => 'Target user ID.'],
                ['type' => 'string', 'enum' => ['all'], 'description' => 'Send to all users in the company.'],
            ])->required()
            ->string(
                'channel',
                'Notification channel: "database" (default) or "broadcast".',
                enum: self::CHANNELS,
            )
            ->string(
                'subject',
                'Notification title/subject (max '.self::MAX_SUBJECT_LENGTH.' characters).',
            )->required()
            ->string(
                'body',
                'Notification body/content (max '.self::MAX_BODY_LENGTH.' characters).',
            )->required();
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
        return 'ai.tool_notification.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Notification',
            'summary' => 'Send notifications to BLB users via internal channels.',
            'explanation' => 'Sends notifications via Laravel\'s notification system (database, email, broadcast). '
                .'Targeted at internal BLB notifications — not an external messaging platform.',
            'setup_requirements' => [
                'Notification channels configured',
            ],
            'health_checks' => [
                'Notification system available',
            ],
            'limits' => [
                'Internal BLB users only',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $userId = $arguments['user_id'] ?? null;

        if ($userId === null) {
            throw new ToolArgumentException('user_id is required.');
        }

        if ($userId !== 'all' && (! is_int($userId) || $userId < 1)) {
            throw new ToolArgumentException('user_id must be a positive integer or the string "all".');
        }

        $channel = $this->requireEnum($arguments, 'channel', self::CHANNELS, 'database');
        $subject = $this->requireString($arguments, 'subject');
        $body = $this->requireString($arguments, 'body');

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new ToolArgumentException('subject must not exceed '.self::MAX_SUBJECT_LENGTH.' characters.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new ToolArgumentException('body must not exceed '.self::MAX_BODY_LENGTH.' characters.');
        }

        try {
            $users = $this->resolveRecipients($userId);
            $this->sendNotification($users, $this->buildNotification($subject, $body, $channel));
        } catch (NotificationToolRecipientException $e) {
            return ToolResult::error($e->getMessage(), 'recipient_error');
        } catch (NotificationToolDeliveryException $e) {
            return ToolResult::error($e->getMessage(), 'delivery_error');
        }

        return ToolResult::success(json_encode([
            'status' => 'sent',
            'recipients' => count($users),
            'channel' => $channel,
            'subject' => $subject,
            'sent_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resolve target users from the user_id argument.
     *
     * @param  int|string  $userId  User ID or "all"
     * @return \Illuminate\Database\Eloquent\Collection<int, User>|array<int, User>
     *
     * @throws NotificationToolRecipientException If the user cannot be resolved
     */
    private function resolveRecipients(int|string $userId): \Illuminate\Database\Eloquent\Collection|array
    {
        if ($userId === 'all') {
            return $this->resolveAllCompanyUsers();
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            throw new NotificationToolRecipientException('User with ID '.$userId.' not found.');
        }

        return [$user];
    }

    /**
     * Resolve all users in the authenticated user's company.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     *
     * @throws NotificationToolRecipientException If no auth user or no company
     */
    private function resolveAllCompanyUsers(): \Illuminate\Database\Eloquent\Collection
    {
        $authUser = Auth::user();

        if (! $authUser instanceof User) {
            throw new NotificationToolRecipientException('No authenticated user. Cannot resolve company users.');
        }

        $companyId = $authUser->getCompanyId();

        if ($companyId === null) {
            throw new NotificationToolRecipientException('Authenticated user has no company. Cannot broadcast to all.');
        }

        $users = User::query()->where('company_id', $companyId)->get();

        if ($users->isEmpty()) {
            throw new NotificationToolRecipientException('No users found in company ID '.$companyId.'.');
        }

        return $users;
    }

    /**
     * Build an anonymous Notification instance for the given content and channel.
     */
    private function buildNotification(string $subject, string $body, string $channel): Notification
    {
        return new class($subject, $body, $channel) extends Notification
        {
            public function __construct(
                private readonly string $subject,
                private readonly string $body,
                private readonly string $channel,
            ) {}

            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return [$this->channel];
            }

            /**
             * @return array<string, string>
             */
            public function toArray(object $notifiable): array
            {
                return [
                    'subject' => $this->subject,
                    'body' => $this->body,
                ];
            }

            public function toBroadcast(object $notifiable): BroadcastMessage
            {
                return new BroadcastMessage([
                    'subject' => $this->subject,
                    'body' => $this->body,
                ]);
            }
        };
    }

    /**
     * @param  iterable<int, User>  $users
     *
     * @throws NotificationToolDeliveryException
     */
    private function sendNotification(iterable $users, Notification $notification): void
    {
        try {
            NotificationFacade::send($users, $notification);
        } catch (\Throwable $e) {
            if ($this->isTableMissing($e)) {
                throw new NotificationToolDeliveryException(
                    'The notifications table does not exist. Run "php artisan notifications:table" and "php artisan migrate" to create it.',
                    previous: $e,
                );
            }

            throw new NotificationToolDeliveryException('Failed to send notification — '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Check whether a throwable indicates a missing database table.
     */
    private function isTableMissing(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'notifications') && (
            str_contains($message, 'does not exist')
            || str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist')
        );
    }
}

/**
 * Thrown when the notification recipient cannot be resolved.
 *
 * Kept as a domain-specific exception (not collapsed into ToolArgumentException)
 * because recipient resolution failures are runtime errors (user not found,
 * no company), not input validation errors.
 */
final class NotificationToolRecipientException extends \RuntimeException {}

/**
 * Thrown when notification delivery fails.
 *
 * Covers infrastructure issues like missing database tables or transport errors.
 */
final class NotificationToolDeliveryException extends \RuntimeException {}
