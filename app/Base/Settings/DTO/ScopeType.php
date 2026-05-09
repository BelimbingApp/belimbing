<?php
namespace App\Base\Settings\DTO;

enum ScopeType: string
{
    case COMPANY = 'company';
    case EMPLOYEE = 'employee';
}
