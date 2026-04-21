<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;

final class ProviderRequestHeaderResolver
{
    /**
     * GitHub Copilot's API rejects requests unless callers identify as a compatible IDE client.
     *
     * @var array<string, string>
     */
    private const GITHUB_COPILOT_HEADERS = [
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    /**
     * @return array<string, string>
     */
    public function headersFor(ChatRequest $request): array
    {
        return match ($request->providerName) {
            'github-copilot' => self::GITHUB_COPILOT_HEADERS,
            'openai-codex' => $this->codexHeaders($request),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function codexHeaders(ChatRequest $request): array
    {
        $accountId = $this->extractCodexAccountId($request->apiKey);

        return array_filter([
            // Required by ChatGPT backend.
            'chatgpt-account-id' => $accountId,
            // Mirrors Codex/OpenClaw usage; not a stable public contract.
            'originator' => 'blb',
            // Codex backend expects Responses experimental headers.
            'OpenAI-Beta' => 'responses=experimental',
        ], fn ($value) => is_string($value) && $value !== '');
    }

    private function extractCodexAccountId(string $token): string
    {
        // Token is expected to be a JWT: header.payload.signature.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return '';
        }

        $payload = $this->base64UrlDecode($parts[1] ?? '');
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return '';
        }

        $claim = $decoded['https://api.openai.com/auth'] ?? null;
        $accountId = is_array($claim) ? ($claim['chatgpt_account_id'] ?? null) : null;

        return is_string($accountId) ? $accountId : '';
    }

    private function base64UrlDecode(string $encoded): string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $pad = strlen($encoded) % 4;
        if ($pad !== 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? '' : $decoded;
    }
}
