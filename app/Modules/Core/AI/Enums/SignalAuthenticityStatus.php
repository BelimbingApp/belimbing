<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Result of inbound signal authenticity verification.
 */
enum SignalAuthenticityStatus: string
{
    /** Signature or token verified successfully. */
    case Verified = 'verified';

    /** Verification failed — signature mismatch or missing. */
    case Failed = 'failed';

    /** Channel does not support verification or verification was skipped. */
    case Skipped = 'skipped';
}
