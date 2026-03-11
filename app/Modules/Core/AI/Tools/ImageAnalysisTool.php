<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * Image analysis tool for Digital Workers.
 *
 * Analyzes images using vision models — describe content, extract text
 * (OCR), identify objects, and answer questions about images. Supports
 * common image formats (JPEG, PNG, GIF, WebP) via storage paths or URLs.
 *
 * Note: Currently returns stub responses. Vision model integration
 * will be implemented once the inference pipeline is deployed.
 *
 * Gated by `ai.tool_image_analysis.execute` authz capability.
 */
class ImageAnalysisTool extends AbstractTool
{
    /**
     * Supported image file extensions.
     *
     * @var list<string>
     */
    private const SUPPORTED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    /**
     * Maximum allowed prompt length in characters.
     */
    private const MAX_PROMPT_LENGTH = 5000;

    public function name(): string
    {
        return 'image_analysis';
    }

    public function description(): string
    {
        return 'Analyze images using vision models — describe content, extract text (OCR), '
            .'identify objects, answer questions about images. '
            .'Supports common formats (JPEG, PNG, GIF, WebP).';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('path', 'Storage path or URL to the image.')->required()
            ->string('prompt', 'What to analyze or question about the image.')->required();
    }

    public function category(): ToolCategory
    {
        return ToolCategory::MEDIA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_image_analysis.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $path = $this->requireString($arguments, 'path');
        $prompt = $this->requireString($arguments, 'prompt');

        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new ToolArgumentException(
                '"prompt" must not exceed '.self::MAX_PROMPT_LENGTH.' characters.'
            );
        }

        $isUrl = str_starts_with($path, 'http://') || str_starts_with($path, 'https://');

        if (! $isUrl) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                throw new ToolArgumentException(
                    'Unsupported image format. Supported extensions: '
                        .implode(', ', self::SUPPORTED_EXTENSIONS).'.'
                );
            }
        }

        return ToolResult::success(json_encode([
            'action' => 'image_analysis',
            'path' => $path,
            'prompt' => $prompt,
            'status' => 'analyzed',
            'message' => 'Image analyzed (stub). Vision model integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
