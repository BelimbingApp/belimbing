<?php

namespace App\Modules\Core\AI\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum AIErrorCode: string implements BlbErrorCode
{
    case LARA_AGENT_ID_TYPE_INVALID = 'lara_agent_id_type_invalid';
    case LARA_PROMPT_CONTEXT_ENCODE_FAILED = 'lara_prompt_context_encode_failed';
    case LARA_PROMPT_RESOURCE_MISSING = 'lara_prompt_resource_missing';
    case LARA_PROMPT_RESOURCE_UNREADABLE = 'lara_prompt_resource_unreadable';
    case WORKSPACE_FILE_UNREADABLE = 'workspace_file_unreadable';
    case WORKSPACE_VALIDATION_FAILED = 'workspace_validation_failed';
    case MEMORY_SOURCE_UNREADABLE = 'memory_source_unreadable';
    case MEMORY_INDEX_FAILED = 'memory_index_failed';
    case MEMORY_INDEX_CORRUPT = 'memory_index_corrupt';
}
