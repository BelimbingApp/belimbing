<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Enums;

enum BlbErrorCode: string
{
    case BLB_CONFIGURATION = 'blb_configuration';
    case BLB_INVARIANT_VIOLATION = 'blb_invariant_violation';
    case BLB_DATA_CONTRACT = 'blb_data_contract';
    case BLB_INTEGRATION = 'blb_integration';

    case DEV_SEEDER_NON_LOCAL_ENV = 'dev_seeder_non_local_env';
    case CIRCULAR_SEEDER_DEPENDENCY = 'circular_seeder_dependency';

    case LARA_AGENT_ID_TYPE_INVALID = 'lara_agent_id_type_invalid';
    case LARA_PROMPT_CONTEXT_ENCODE_FAILED = 'lara_prompt_context_encode_failed';
    case LARA_PROMPT_RESOURCE_MISSING = 'lara_prompt_resource_missing';
    case LARA_PROMPT_RESOURCE_UNREADABLE = 'lara_prompt_resource_unreadable';

    case WORKSPACE_FILE_UNREADABLE = 'workspace_file_unreadable';
    case WORKSPACE_VALIDATION_FAILED = 'workspace_validation_failed';

    case AUTHZ_DENIED = 'authz_denied';
    case AUTHZ_UNKNOWN_CAPABILITY = 'authz_unknown_capability';

    case DATABASE_QUERY_INVALID = 'database_query_invalid';
    case DATABASE_QUERY_EXECUTION_FAILED = 'database_query_execution_failed';

    case LICENSEE_COMPANY_DELETION_FORBIDDEN = 'licensee_company_deletion_forbidden';
    case SYSTEM_EMPLOYEE_DELETION_FORBIDDEN = 'system_employee_deletion_forbidden';
}
