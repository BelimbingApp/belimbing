<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging\Adapters;

use App\Modules\Core\AI\DTO\Messaging\ChannelAccount;
use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Modules\Core\AI\DTO\Messaging\SendResult;
use App\Modules\Core\AI\Mail\ChannelOutboundMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email channel adapter — outbound via Laravel Mail.
 *
 * Sends text and media messages through the configured Laravel mailer.
 * Account credentials may optionally override the from address and mailer
 * driver. Inbound parsing is deferred to Stage C (webhook/IMAP integration).
 *
 * Credential fields (from ChannelAccount DTO):
 *   - `from_address`: sender email (falls back to mail.from config)
 *   - `from_name`: sender display name
 *   - `mailer`: Laravel mailer name override (e.g., 'smtp', 'ses')
 */
class EmailAdapter extends BaseChannelAdapter
{
    protected function channelKey(): string
    {
        return 'email';
    }

    protected function channelLabel(): string
    {
        return 'Email';
    }

    /**
     * Send a text email to the target address.
     *
     * Overrides BaseChannelAdapter stub to deliver via Laravel Mail.
     *
     * @param  ChannelAccount  $account  Channel account with optional credential overrides
     * @param  string  $target  Recipient email address
     * @param  string  $text  Message body (plain text)
     * @param  array{subject?: string, reply_to?: string}  $options  Optional subject and reply-to
     */
    public function sendText(ChannelAccount $account, string $target, string $text, array $options = []): SendResult
    {
        return $this->dispatch($account, $target, $text, $options);
    }

    /**
     * Send an email with a media attachment.
     *
     * Overrides BaseChannelAdapter stub to deliver via Laravel Mail with
     * the media file attached.
     *
     * @param  ChannelAccount  $account  Channel account with optional credential overrides
     * @param  string  $target  Recipient email address
     * @param  string  $mediaPath  Path to the file to attach
     * @param  string|null  $caption  Optional message body (sent as text alongside the attachment)
     */
    public function sendMedia(ChannelAccount $account, string $target, string $mediaPath, ?string $caption = null): SendResult
    {
        return $this->dispatch($account, $target, $caption ?? '', [
            'attachment' => $mediaPath,
        ]);
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsMedia: true,
            supportsSearch: true,
            maxMessageLength: 100000,
            mediaTypes: ['image', 'document', 'audio', 'video'],
        );
    }

    /**
     * Build and send the email through Laravel Mail.
     *
     * @param  ChannelAccount  $account  Account with credential overrides
     * @param  string  $target  Recipient email address
     * @param  string  $text  Plain-text body
     * @param  array<string, mixed>  $options  Subject, reply_to, attachment, etc.
     */
    private function dispatch(ChannelAccount $account, string $target, string $text, array $options = []): SendResult
    {
        $fromAddress = $account->credentials['from_address'] ?? null;
        $fromName = $account->credentials['from_name'] ?? null;
        $mailer = $account->credentials['mailer'] ?? null;
        $subject = $options['subject'] ?? 'Message from BLB';

        $mailable = new ChannelOutboundMail(
            body: $text,
            emailSubject: $subject,
            fromAddress: $fromAddress,
            fromName: $fromName,
        );

        if (isset($options['attachment']) && is_string($options['attachment'])) {
            $mailable->attach(Attachment::fromPath($options['attachment']));
        }

        try {
            $mailerInstance = $mailer !== null
                ? Mail::mailer($mailer)
                : Mail::mailer();

            $mailerInstance->to($target)->send($mailable);

            // Laravel Mail does not return a platform message ID for SMTP,
            // so we generate a deterministic reference for tracking.
            $messageId = 'email_'.Str::random(16);

            return SendResult::ok($messageId);
        } catch (\Throwable $e) {
            return SendResult::fail('Email delivery failed: '.$e->getMessage());
        }
    }
}
