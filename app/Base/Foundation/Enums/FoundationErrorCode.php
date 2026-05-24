<?php

namespace App\Base\Foundation\Enums;

enum FoundationErrorCode: string implements BlbErrorCode
{
    case BLB_CONFIGURATION = 'blb_configuration';
    case BLB_INVARIANT_VIOLATION = 'blb_invariant_violation';
    case BLB_DATA_CONTRACT = 'blb_data_contract';
    case BLB_INTEGRATION = 'blb_integration';
}
