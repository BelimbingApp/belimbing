<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic outbound email sent through the AI messaging channel.
 *
 * Used by the EmailAdapter to deliver text messages composed by agents.
 * Renders as a simple text email with an optional subject line. Media
 * attachments are handled by attaching files to the Mailable.
 */
class ChannelOutboundMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $body  Plain-text message body
     * @param  string  $emailSubject  Email subject line
     * @param  string|null  $fromAddress  Sender address override (null uses default)
     * @param  string|null  $fromName  Sender name override
     */
    public function __construct(
        public readonly string $body,
        public readonly string $emailSubject = 'Message from Belimbing',
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress !== null
                ? new Address($this->fromAddress, $this->fromName ?? config('app.name', 'Belimbing'))
                : null,
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.ai.channel-outbound-text',
        );
    }
}
