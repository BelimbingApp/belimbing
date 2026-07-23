<?php

namespace App\Modules\Core\AI\Services\Browser;

use App\Base\AI\Services\AiRuntimeSettings;

/**
 * Manages isolated browser context creation for browser automation.
 *
 * Handles Playwright CLI resolution and produces unique context identifiers
 * scoped by company and session. Supports both headful and headless modes
 * based on environment configuration. Actual Chromium process lifecycle is
 * delegated to BrowserPoolManager.
 */
class BrowserContextFactory
{
    public function __construct(
        private readonly ?AiRuntimeSettings $runtimeSettings = null,
    ) {}

    /**
     * Resolve the path to the Playwright CLI binary.
     *
     * Checks the declared installation setting first, then falls back to
     * auto-detection of npx playwright in the project.
     *
     * @return string|null Path to binary, or null if not found
     */
    public function resolvePlaywrightPath(): ?string
    {
        $configured = $this->runtimeSettings()->browserExecutablePath();

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $npxPath = base_path('node_modules/.bin/playwright');

        if (file_exists($npxPath)) {
            return $npxPath;
        }

        return null;
    }

    /**
     * Check whether the browser automation infrastructure is available.
     *
     * Requires the tool to be enabled, Playwright installed, and the
     * Node.js runner script present.
     */
    public function isAvailable(): bool
    {
        return $this->runtimeSettings()->browserEnabled()
            && $this->resolvePlaywrightPath() !== null
            && file_exists(resource_path('core/scripts/browser-runner.mjs'));
    }

    /**
     * Create a new isolated browser context identifier.
     *
     * Returns a unique context ID. The actual Chromium context lifecycle
     * is managed by BrowserPoolManager.
     *
     * @param  int  $companyId  Company scope for isolation
     * @param  string  $sessionId  agent session identifier
     * @return string Unique context identifier
     */
    public function createContextId(int $companyId, string $sessionId): string
    {
        return 'ctx_'.$companyId.'_'.$sessionId;
    }

    private function runtimeSettings(): AiRuntimeSettings
    {
        return $this->runtimeSettings ?? app(AiRuntimeSettings::class);
    }
}
