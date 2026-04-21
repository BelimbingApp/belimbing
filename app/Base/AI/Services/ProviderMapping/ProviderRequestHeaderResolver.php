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
            'openai-codex' => $this->codexHeaders(),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function codexHeaders(): array
    {
        return array_filter([
            // Mirrors Codex/OpenClaw usage; not a stable public contract.
            'originator' => 'blb',
            // Codex backend expects Responses experimental headers.
            'OpenAI-Beta' => 'responses=experimental',
        ], fn ($value) => is_string($value) && $value !== '');
    }
}
