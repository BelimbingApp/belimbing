<?php

namespace App\Core\User\Notifications;

use App\Base\System\Enums\MailPurpose;
use App\Base\System\Services\MailRuntimeSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Framework VerifyEmail, decorated with the effective account-security
 * sender identity (#377) rather than the raw global default. Keeps the
 * framework's subject/body copy — only From/Reply-To change.
 */
class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $identity = app(MailRuntimeSettings::class)->effectiveIdentity(MailPurpose::AccountSecurity);

        $message = parent::toMail($notifiable)->from($identity['address'], $identity['name']);

        if ($identity['reply_to'] !== null) {
            $message->replyTo($identity['reply_to']);
        }

        return $message;
    }
}
