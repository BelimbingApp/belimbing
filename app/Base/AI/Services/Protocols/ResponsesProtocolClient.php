<?php
namespace App\Base\AI\Services\Protocols;

final class ResponsesProtocolClient extends AbstractResponsesProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'responses';
    }
}
