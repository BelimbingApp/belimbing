<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Modules\Core\AI\DTO\ToolConfigField;
use App\Modules\Core\AI\DTO\ToolMetadata;

/**
 * Rich UI metadata registry for Digital Worker tools.
 *
 * Provides display-oriented information (names, descriptions, categories,
 * risk classes, setup requirements, test examples) for the Tool Workspace UI.
 * Keyed by the tool's machine name matching Tool::name().
 */
class ToolMetadataRegistry
{
    private const NO_EXTERNAL_SETUP = ['No external configuration required'];

    /** @var array<string, ToolMetadata> */
    private array $metadata = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Get metadata for a specific tool.
     *
     * @param  string  $name  Tool machine name
     */
    public function get(string $name): ?ToolMetadata
    {
        return $this->metadata[$name] ?? null;
    }

    /**
     * Get metadata for all known tools.
     *
     * @return array<string, ToolMetadata>
     */
    public function all(): array
    {
        return $this->metadata;
    }

    /**
     * Register metadata for a tool.
     */
    public function register(ToolMetadata $metadata): void
    {
        $this->metadata[$metadata->name] = $metadata;
    }

    /**
     * Check whether metadata exists for a given tool name.
     */
    public function has(string $name): bool
    {
        return isset($this->metadata[$name]);
    }

    /**
     * Populate the registry with metadata for all built-in tools.
     */
    private function registerDefaults(): void
    {
        $definitions = $this->defaultDefinitions();

        foreach ($definitions as $def) {
            $this->metadata[$def->name] = $def;
        }
    }

    /**
     * Default metadata definitions for all built-in Digital Worker tools.
     *
     * @return list<ToolMetadata>
     */
    private function defaultDefinitions(): array
    {
        return [
            ...$this->foundationDefinitions(),
            ...$this->memoryDefinitions(),
            ...$this->delegationDefinitions(),
            ...$this->automationDefinitions(),
            ...$this->browserDefinitions(),
            ...$this->systemDefinitions(),
            ...$this->messagingDefinitions(),
            ...$this->mediaDefinitions(),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function foundationDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'query_data',
                displayName: 'Query Data',
                summary: 'Read data from BLB using safe, read-only SQL.',
                explanation: 'Executes SELECT queries against the application database to answer data questions. '
                    .'Only read-only operations are allowed — write statements (INSERT, UPDATE, DELETE, DROP, etc.) '
                    .'are rejected at the SQL parsing level. Results are returned as formatted tables. '
                    .'This tool cannot modify data or schema.',
                category: ToolCategory::DATA,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_query_data.execute',
                setupRequirements: [
                    'No external API key required',
                    'Database connection must be available',
                ],
                testExamples: [
                    ['label' => 'Count employees', 'input' => ['query' => 'SELECT count(*) AS total FROM employees']],
                    ['label' => 'List tables', 'input' => ['query' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' LIMIT 10"]],
                ],
                healthChecks: [
                    'Database reachable',
                    'Read-only SQL validator active',
                ],
                limits: [
                    'Maximum 100 rows per query',
                    '10-second statement timeout',
                    'SELECT and WITH statements only',
                ],
            ),
            new ToolMetadata(
                name: 'web_search',
                displayName: 'Web Search',
                summary: 'Search the public web and return summarized results.',
                explanation: 'Searches the web for current information using a configured provider (Parallel or Brave Search). '
                    .'Results include titles, URLs, and snippets. Cached for 15 minutes to reduce API calls. '
                    .'This tool cannot access private networks or internal resources.',
                category: ToolCategory::WEB,
                riskClass: ToolRiskClass::EXTERNAL_IO,
                capability: 'ai.tool_web_search.execute',
                setupRequirements: [
                    'Search provider selected (Parallel or Brave)',
                    'API key configured for the selected provider',
                ],
                testExamples: [
                    ['label' => 'Simple search', 'input' => ['query' => 'Laravel 12 new features']],
                    ['label' => 'Recent news', 'input' => ['query' => 'latest PHP releases', 'freshness' => 'week']],
                ],
                healthChecks: [
                    'Provider API key present',
                    'Provider endpoint reachable',
                ],
                limits: [
                    'Maximum 10 results per query',
                    '15-second API timeout',
                    '15-minute result cache TTL',
                ],
                configFields: [
                    new ToolConfigField(
                        key: 'ai.tools.web_search.provider',
                        label: 'Search Provider',
                        type: 'select',
                        options: ['parallel' => 'Parallel', 'brave' => 'Brave Search'],
                        help: 'Select which search provider to use.',
                    ),
                    new ToolConfigField(
                        key: 'ai.tools.web_search.parallel.api_key',
                        label: 'Parallel API Key',
                        type: 'secret',
                        encrypted: true,
                        help: 'API key for the Parallel search provider.',
                        showWhen: 'ai.tools.web_search.provider=parallel',
                    ),
                    new ToolConfigField(
                        key: 'ai.tools.web_search.brave.api_key',
                        label: 'Brave Search API Key',
                        type: 'secret',
                        encrypted: true,
                        help: 'API key for Brave Search.',
                        showWhen: 'ai.tools.web_search.provider=brave',
                    ),
                    new ToolConfigField(
                        key: 'ai.tools.web_search.cache_ttl_minutes',
                        label: 'Cache TTL (minutes)',
                        type: 'text',
                        help: 'How long to cache search results.',
                    ),
                ],
            ),
            new ToolMetadata(
                name: 'web_fetch',
                displayName: 'Web Fetch',
                summary: 'Fetch and extract content from a public URL with SSRF protection.',
                explanation: 'Fetches a web page via HTTP GET and extracts readable content as plain text or markdown. '
                    .'SSRF protection blocks requests to private/internal networks by default. '
                    .'Useful for reading documentation, articles, and public web pages. '
                    .'This tool cannot access internal services or bypass network restrictions.',
                category: ToolCategory::WEB,
                riskClass: ToolRiskClass::EXTERNAL_IO,
                capability: 'ai.tool_web_fetch.execute',
                setupRequirements: [
                    'Outbound HTTP access available',
                    'SSRF guard enabled (default)',
                ],
                testExamples: [
                    ['label' => 'Fetch documentation', 'input' => ['url' => 'https://laravel.com/docs/12.x/installation', 'extract_mode' => 'markdown']],
                    ['label' => 'Fetch as text', 'input' => ['url' => 'https://example.com', 'max_chars' => 1000]],
                ],
                healthChecks: [
                    'HTTP client connectivity',
                    'SSRF guard operational',
                ],
                limits: [
                    '5 MB maximum response size',
                    '50,000 character content cap (configurable)',
                    '30-second timeout',
                    '5 redirect maximum',
                ],
                configFields: [
                    new ToolConfigField(
                        key: 'ai.tools.web_fetch.timeout_seconds',
                        label: 'Timeout (seconds)',
                        type: 'text',
                        help: 'Maximum time to wait for a response.',
                    ),
                    new ToolConfigField(
                        key: 'ai.tools.web_fetch.max_response_bytes',
                        label: 'Max Response Size (bytes)',
                        type: 'text',
                        help: 'Maximum response body size.',
                    ),
                ],
            ),
            new ToolMetadata(
                name: 'system_info',
                displayName: 'System Info',
                summary: 'Inspect non-sensitive BLB system state for diagnostics.',
                explanation: 'Reports structured information about the BLB instance: framework versions, active modules, '
                    .'configured AI providers (keys masked), and health status. Useful for diagnostics and system awareness. '
                    .'This tool cannot modify system configuration or expose secrets.',
                category: ToolCategory::SYSTEM,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_system_info.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                testExamples: [
                    ['label' => 'Full overview', 'input' => ['section' => 'all']],
                    ['label' => 'Health check', 'input' => ['section' => 'health']],
                    ['label' => 'Active modules', 'input' => ['section' => 'modules']],
                ],
                healthChecks: [
                    'System data providers available',
                ],
                limits: [
                    'API keys and secrets are always masked',
                    'Read-only — cannot modify system state',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function memoryDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'memory_search',
                displayName: 'Memory Search',
                summary: 'Search across workspace knowledge using semantic and keyword matching.',
                explanation: 'Performs hybrid vector + keyword search over markdown files in the DW workspace. '
                    .'Requires embedding provider configuration and indexed workspace content. '
                    .'This tool only reads indexed workspace files — it cannot access arbitrary files.',
                category: ToolCategory::MEMORY,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_memory_search.execute',
                setupRequirements: [
                    'Docs directory must exist',
                    'Workspace content indexed',
                ],
                testExamples: [
                    ['label' => 'Search for topic', 'input' => ['query' => 'authorization capabilities']],
                ],
                healthChecks: [
                    'Docs directory accessible',
                    'Index up to date',
                ],
                limits: [
                    'Searches workspace files only',
                    'Maximum 10 results by default',
                ],
            ),
            new ToolMetadata(
                name: 'memory_get',
                displayName: 'Memory Get',
                summary: 'Read a specific knowledge file from the DW workspace.',
                explanation: 'Reads the content of a file within the DW workspace by path. '
                    .'Path validation prevents directory traversal. '
                    .'This tool can only read files within the designated workspace directory.',
                category: ToolCategory::MEMORY,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_memory_get.execute',
                setupRequirements: [
                    'Workspace directory must exist',
                ],
                testExamples: [
                    ['label' => 'Read a file', 'input' => ['path' => 'MEMORY.md']],
                ],
                healthChecks: [
                    'Workspace directory accessible',
                ],
                limits: [
                    'Workspace files only — no arbitrary filesystem access',
                    'Path traversal blocked',
                ],
            ),
            new ToolMetadata(
                name: 'guide',
                displayName: 'Guide',
                summary: 'Query BLB framework documentation for reference information.',
                explanation: 'Searches the BLB documentation directory for relevant sections on a given topic. '
                    .'Returns curated reference material to help answer framework questions. '
                    .'This tool reads documentation only — it cannot modify docs.',
                category: ToolCategory::MEMORY,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_guide.execute',
                setupRequirements: [
                    'Documentation directory must be present',
                ],
                testExamples: [
                    ['label' => 'Lookup topic', 'input' => ['topic' => 'authorization']],
                ],
                healthChecks: [
                    'Docs directory accessible',
                ],
                limits: [
                    'Returns up to 5 sections by default',
                    'Read-only access to docs/',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function delegationDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'delegate_task',
                displayName: 'Delegate Task',
                summary: 'Dispatch work to another Digital Worker.',
                explanation: 'Queues a task for execution by another Digital Worker. Returns a dispatch ID immediately. '
                    .'The dispatched DW runs asynchronously via Laravel queues. '
                    .'This tool can only delegate to workers the current user supervises.',
                category: ToolCategory::DELEGATION,
                riskClass: ToolRiskClass::INTERNAL,
                capability: 'ai.tool_delegate.execute',
                setupRequirements: [
                    'At least one other Digital Worker configured',
                    'Laravel queue worker running',
                ],
                testExamples: [
                    ['label' => 'Delegate a task', 'input' => ['task' => 'Summarize today\'s activity']],
                ],
                healthChecks: [
                    'Queue connection active',
                    'Delegable workers available',
                ],
                limits: [
                    'Default 300-second timeout per delegation',
                    'Scoped to supervised workers',
                ],
            ),
            new ToolMetadata(
                name: 'delegation_status',
                displayName: 'Delegation Status',
                summary: 'Check the status of a previously dispatched task.',
                explanation: 'Queries the status of a task dispatched via Delegate Task by its dispatch ID. '
                    .'Returns status (queued/running/completed/failed), timing, and result preview.',
                category: ToolCategory::DELEGATION,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_delegation_status.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                limits: [
                    'Read-only status check',
                ],
            ),
            new ToolMetadata(
                name: 'worker_list',
                displayName: 'Worker List',
                summary: 'List available Digital Workers that can receive delegated tasks.',
                explanation: 'Returns a list of Digital Workers the current user supervises, along with their capabilities and status. '
                    .'Useful for deciding which worker to delegate a task to.',
                category: ToolCategory::DELEGATION,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_worker_list.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                limits: [
                    'Shows supervised workers only',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function automationDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'schedule_task',
                displayName: 'Schedule Task',
                summary: 'Create and manage scheduled tasks for Digital Workers.',
                explanation: 'CRUD operations for scheduled tasks stored in the database. Each task defines a cron expression, '
                    .'target DW, and task description. Tasks execute via Laravel\'s scheduler.',
                category: ToolCategory::AUTOMATION,
                riskClass: ToolRiskClass::INTERNAL,
                capability: 'ai.tool_schedule.execute',
                setupRequirements: [
                    'Laravel scheduler running',
                ],
                testExamples: [
                    ['label' => 'List schedules', 'input' => ['action' => 'list']],
                ],
                healthChecks: [
                    'Scheduler active',
                ],
                limits: [
                    'Company-scoped task isolation',
                ],
            ),
            new ToolMetadata(
                name: 'notification',
                displayName: 'Notification',
                summary: 'Send notifications to BLB users via internal channels.',
                explanation: 'Sends notifications via Laravel\'s notification system (database, email, broadcast). '
                    .'Targeted at internal BLB notifications — not an external messaging platform.',
                category: ToolCategory::AUTOMATION,
                riskClass: ToolRiskClass::INTERNAL,
                capability: 'ai.tool_notification.execute',
                setupRequirements: [
                    'Notification channels configured',
                ],
                healthChecks: [
                    'Notification system available',
                ],
                limits: [
                    'Internal BLB users only',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function browserDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'navigate',
                displayName: 'Navigate',
                summary: 'Navigate the user to a page within BLB.',
                explanation: 'Triggers client-side SPA navigation to a BLB page. The LLM uses this to direct users to relevant screens. '
                    .'Navigation is limited to internal BLB routes.',
                category: ToolCategory::BROWSER,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_navigate.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                limits: [
                    'Internal BLB routes only',
                ],
            ),
            new ToolMetadata(
                name: 'browser',
                displayName: 'Browser',
                summary: 'Automate headless browser actions for web scraping and RPA.',
                explanation: 'Server-side headless Chromium automation for navigating, capturing snapshots, clicking, typing, '
                    .'and extracting content from external websites. Enterprise-grade RPA capability. '
                    .'This tool can interact with external websites on behalf of the business.',
                category: ToolCategory::BROWSER,
                riskClass: ToolRiskClass::BROWSER,
                capability: 'ai.tool_browser.execute',
                setupRequirements: [
                    'Headless browser configured',
                    'Browser pool available',
                ],
                testExamples: [
                    ['label' => 'Navigate to URL', 'input' => ['action' => 'navigate', 'url' => 'https://example.com']],
                ],
                healthChecks: [
                    'Browser pool available',
                    'Chromium process responsive',
                ],
                limits: [
                    'Company-scoped browser contexts',
                    'Session isolation between DWs',
                ],
                configFields: [
                    new ToolConfigField(
                        key: 'ai.tools.browser.enabled',
                        label: 'Enable Browser Tool',
                        type: 'boolean',
                        help: 'Whether headless browser automation is enabled.',
                    ),
                    new ToolConfigField(
                        key: 'ai.tools.browser.executable_path',
                        label: 'Chromium Path',
                        type: 'text',
                        help: 'Path to the Chromium executable. Leave empty for auto-detection.',
                    ),
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function systemDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'artisan',
                displayName: 'Artisan',
                summary: 'Execute Laravel artisan commands.',
                explanation: 'Runs `php artisan` commands within the BLB application. Useful for system administration tasks. '
                    .'This is a powerful tool that can modify application state — use with appropriate authorization.',
                category: ToolCategory::SYSTEM,
                riskClass: ToolRiskClass::HIGH_IMPACT,
                capability: 'ai.tool_artisan.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                testExamples: [
                    ['label' => 'List routes', 'input' => ['command' => 'route:list --compact']],
                    ['label' => 'Create a user', 'input' => ['command' => "blb:user:create alice@example.com --name='Alice Smith' --role=core_admin"]],
                    ['label' => '⚠ Wipe database (destroys all data)', 'input' => ['command' => 'db:wipe --force']],
                ],
                healthChecks: [
                    'Artisan process available',
                ],
                limits: [
                    'Commands execute in the application context',
                ],
            ),
            new ToolMetadata(
                name: 'bash',
                displayName: 'Bash',
                summary: 'Execute shell commands on the server.',
                explanation: 'Runs shell commands on the BLB server. Extremely powerful — can modify files, install packages, '
                    .'and interact with the operating system. Requires the highest authorization level.',
                category: ToolCategory::SYSTEM,
                riskClass: ToolRiskClass::HIGH_IMPACT,
                capability: 'ai.tool_bash.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                testExamples: [
                    ['label' => 'Disk usage', 'input' => ['command' => 'df -h']],
                    ['label' => '⚠ Clear application logs (irreversible)', 'input' => ['command' => 'truncate -s 0 storage/logs/laravel.log && echo "Log cleared."']],
                ],
                healthChecks: [
                    'Shell access available',
                ],
                limits: [
                    'Full server access — authorize carefully',
                ],
            ),
            new ToolMetadata(
                name: 'write_js',
                displayName: 'Write JS',
                summary: 'Execute JavaScript in the user\'s browser.',
                explanation: 'Sends JavaScript code to be executed client-side in the user\'s browser session. '
                    .'Useful for UI interactions and dynamic page modifications.',
                category: ToolCategory::BROWSER,
                riskClass: ToolRiskClass::HIGH_IMPACT,
                capability: 'ai.tool_write_js.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                testExamples: [
                    ['label' => 'Scroll to top', 'input' => ['script' => 'window.scrollTo({top: 0, behavior: "smooth"})', 'description' => 'Scroll the page to the top']],
                    ['label' => '⚠ Redirect user to another page', 'input' => ['script' => 'window.location.href = "/dashboard"', 'description' => 'Navigate user away from current page']],
                ],
                limits: [
                    'Executes in the user\'s browser context',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function messagingDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'message',
                displayName: 'Message',
                summary: 'Send messages across WhatsApp, Telegram, Slack, and other channels.',
                explanation: 'Multi-channel messaging tool that allows Digital Workers to communicate with customers, '
                    .'partners, and teams. Supports WhatsApp, Telegram, LinkedIn, Slack, email, and more. '
                    .'Each channel requires separate account configuration and authorization.',
                category: ToolCategory::MESSAGING,
                riskClass: ToolRiskClass::MESSAGING,
                capability: 'ai.tool_message.execute',
                setupRequirements: [
                    'At least one messaging channel account configured',
                    'Channel-specific credentials set up',
                ],
                healthChecks: [
                    'Channel adapter registry loaded',
                    'At least one channel configured',
                ],
                limits: [
                    'Each channel gated by separate authz capabilities',
                    'Company-scoped account isolation',
                ],
            ),
        ];
    }

    /**
     * @return list<ToolMetadata>
     */
    private function mediaDefinitions(): array
    {
        return [
            new ToolMetadata(
                name: 'document_analysis',
                displayName: 'Document Analysis',
                summary: 'Analyze and extract information from uploaded documents.',
                explanation: 'Processes documents (PDF, text, etc.) to extract content, summarize, or answer questions about them. '
                    .'Documents must be uploaded or referenced by the user.',
                category: ToolCategory::MEDIA,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_document_analysis.execute',
                setupRequirements: self::NO_EXTERNAL_SETUP,
                limits: [
                    'Read-only document processing',
                ],
            ),
            new ToolMetadata(
                name: 'image_analysis',
                displayName: 'Image Analysis',
                summary: 'Analyze and describe uploaded images.',
                explanation: 'Processes images to describe content, extract text, or answer questions about visual elements. '
                    .'Images must be uploaded or referenced by the user.',
                category: ToolCategory::MEDIA,
                riskClass: ToolRiskClass::READ_ONLY,
                capability: 'ai.tool_image_analysis.execute',
                setupRequirements: [
                    'Vision-capable LLM model configured',
                ],
                limits: [
                    'Read-only image processing',
                ],
            ),
        ];
    }
}
