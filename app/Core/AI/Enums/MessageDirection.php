<?php
namespace App\Core\AI\Enums;

/**
 * Direction of a conversation message.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
