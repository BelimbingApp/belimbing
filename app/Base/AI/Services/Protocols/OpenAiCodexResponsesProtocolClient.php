<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

/**
 * OpenAI Codex responses protocol client (ChatGPT backend API).
 *
 * Uses the OpenAI Responses event format, but a different endpoint path:
 * POST /codex/responses (not /responses).
 */
final class OpenAiCodexResponsesProtocolClient extends ResponsesProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'codex/responses';
    }
}

