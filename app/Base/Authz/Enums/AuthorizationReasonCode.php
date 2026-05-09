<?php
namespace App\Base\Authz\Enums;

enum AuthorizationReasonCode: string
{
    case ALLOWED = 'allowed';
    case DENIED_UNKNOWN_CAPABILITY = 'denied_unknown_capability';
    case DENIED_INVALID_ACTOR_CONTEXT = 'denied_invalid_actor_context';
    case DENIED_COMPANY_SCOPE = 'denied_company_scope';
    case DENIED_MISSING_CAPABILITY = 'denied_missing_capability';
    case DENIED_EXPLICITLY = 'denied_explicitly';
    case DENIED_POLICY_ENGINE_ERROR = 'denied_policy_engine_error';
}
