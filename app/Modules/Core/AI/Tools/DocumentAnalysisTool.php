<?php
namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * Document analysis tool for Agents.
 *
 * Analyzes PDFs and documents — extract text, summarize, and answer questions
 * about document content. For providers supporting native PDF (Anthropic,
 * Google), raw bytes are sent. For others, text is extracted via PDF parser.
 *
 * Note: Currently returns stub responses. PDF parser and LLM integration
 * will be implemented once the document processing infrastructure is deployed.
 *
 * Gated by `admin.ai.tool.document-analysis.execute` authz capability.
 */
class DocumentAnalysisTool extends AbstractTool
{
    /**
     * Maximum length for the pages filter string.
     */
    private const MAX_PAGES_LENGTH = 100;

    /**
     * Maximum length for the prompt string.
     */
    private const MAX_PROMPT_LENGTH = 5000;

    /**
     * Regex pattern for validating page filter expressions.
     *
     * Supports single pages (1), ranges (1-5), and comma-separated
     * combinations (1,3,7 or 1-3,5,8-10).
     */
    private const PAGES_PATTERN = '/^\d+(-\d+)?(,\d+(-\d+)?)*$/';

    public function name(): string
    {
        return 'document_analysis';
    }

    public function description(): string
    {
        return 'Analyze PDFs and documents — extract text, summarize, answer questions about '
            .'document content. For providers supporting native PDF (Anthropic, Google), raw bytes '
            .'are sent. For others, text is extracted via PDF parser.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('path', 'Storage path or URL to the document to analyze.')->required()
            ->string(
                'prompt',
                'What to analyze or extract from the document (max '.self::MAX_PROMPT_LENGTH.' characters).'
            )->required()
            ->string(
                'pages',
                'Page filter expression, e.g. "1-5" or "1,3,7" or "1-3,5,8-10" '
                    .'(max '.self::MAX_PAGES_LENGTH.' characters). Optional; defaults to all pages.'
            )
            ->string(
                'model',
                'LLM model override for analysis. Optional; uses the default model if not specified.'
            );
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
        return 'admin.ai.tool.document-analysis.execute';
    }

    /**
     * Human-friendly display name for UI surfaces.
     */
    public function displayName(): string
    {
        return 'Document Analysis';
    }

    /**
     * One-sentence plain-language summary for humans.
     */
    public function summary(): string
    {
        return 'Analyze and extract information from uploaded documents.';
    }

    /**
     * Longer explanation of what this tool does and does not do.
     */
    public function explanation(): string
    {
        return 'Processes documents (PDF, text, etc.) to extract content, summarize, or answer '
            .'questions about them. Documents must be uploaded or referenced by the user.';
    }

    /**
     * Known safety limits users should understand.
     *
     * @return list<string>
     */
    public function limits(): array
    {
        return [
            'Read-only document processing',
        ];
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

        $pages = $this->optionalString($arguments, 'pages');
        $model = $this->optionalString($arguments, 'model');

        if ($pages !== null) {
            $this->validatePages($pages);
        }

        $data = [
            'action' => 'document_analysis',
            'path' => $path,
            'prompt' => $prompt,
        ];

        if ($pages !== null) {
            $data['pages'] = $pages;
        }

        if ($model !== null) {
            $data['model'] = $model;
        }

        $data['status'] = 'analyzed';
        $data['message'] = 'Document analyzed (stub). PDF parser and LLM integration pending.';

        return ToolResult::success(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Validate the pages filter expression.
     *
     * @param  string  $pages  Trimmed pages string
     *
     * @throws ToolArgumentException If the pages string is invalid
     */
    private function validatePages(string $pages): void
    {
        if (mb_strlen($pages) > self::MAX_PAGES_LENGTH) {
            throw new ToolArgumentException(
                '"pages" must not exceed '.self::MAX_PAGES_LENGTH.' characters.'
            );
        }

        if (! preg_match(self::PAGES_PATTERN, $pages)) {
            throw new ToolArgumentException(
                'Invalid "pages" format. '
                    .'Expected patterns like "1-5", "1,3,7", or "1-3,5,8-10".'
            );
        }
    }
}
