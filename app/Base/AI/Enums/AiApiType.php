<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

/**
 * Wire protocol type for LLM API calls.
 *
 * Determines which endpoint and request/response format LlmClient uses.
 * The api type is a per-model property resolved from the provider overlay
 * config — LlmClient dispatches on this enum without inspecting model names.
 *
 * Naming follows OpenClaw's model catalog taxonomy (openai-completions,
 * openai-responses, etc.) adapted to BLB conventions.
 */
enum AiApiType: string
{
    /** OpenAI Chat Completions API — POST /chat/completions */
    case OpenAiChatCompletions = 'openai_chat_completions';

    /** OpenAI Responses API — POST /responses */
    case OpenAiResponses = 'openai_responses';

    /** Anthropic Messages API — POST /messages */
    case AnthropicMessages = 'anthropic_messages';

    // Future — only when BLB adds more native provider SDKs:
    // case GoogleGenerativeAi = 'google_generative_ai';
    // case BedrockConverseStream = 'bedrock_converse_stream';
}
