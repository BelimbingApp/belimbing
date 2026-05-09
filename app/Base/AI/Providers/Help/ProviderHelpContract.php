<?php
namespace App\Base\AI\Providers\Help;

interface ProviderHelpContract
{
    /**
     * Ordered setup steps to get this provider running.
     *
     * @return list<string>
     */
    public function setupSteps(): array;

    /**
     * Common troubleshooting tips for this provider.
     *
     * @return list<string>
     */
    public function troubleshootingTips(): array;

    /**
     * URL to the provider's official documentation or setup guide.
     */
    public function documentationUrl(): ?string;

    /**
     * Short, actionable advice shown inline next to a connection error.
     */
    public function connectionErrorAdvice(): string;
}
