<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

final class ResponsesProtocolClient extends AbstractResponsesProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'responses';
    }
}
