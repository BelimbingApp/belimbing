<?php

namespace App\Base\System\Enums;

/**
 * The operator-facing reasons Belimbing sends mail — distinct from the SMTP
 * credential (how delivery authenticates) and the From identity (what the
 * override falls back to). Each purpose may optionally override From
 * name/address and Reply-To; unset, it uses the global sender (#377).
 */
enum MailPurpose: string
{
    /** Password reset and email verification — real outbound flows today. */
    case AccountSecurity = 'account_security';

    /**
     * Application/workflow notifications delivered by email. Not consumed by
     * anything yet: ticket and workflow notifications go to the database
     * channel today, so this purpose has no active sender until a mail
     * notification channel exists. The setting exists so the vocabulary and
     * fallback contract are ready when one is added, not because it is
     * required now.
     */
    case Notifications = 'notifications';

    public function settingsKeyPrefix(): string
    {
        return "mail.purposes.{$this->value}.";
    }
}
