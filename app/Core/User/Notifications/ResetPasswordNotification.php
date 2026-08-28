<?php

namespace App\Core\User\Notifications;

use App\Base\System\Enums\MailPurpose;
use App\Base\System\Services\MailRuntimeSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Framework ResetPassword, decorated with the effective account-security
 * sender identity (#377) rather than the raw global default. Keeps the
 * framework's subject/body/expiry copy — only From/Reply-To change.
 */
class ResetPasswordNotification extends ResetPassword
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
