<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Display browser session status for a company or specific session.
 *
 * Provides operator visibility into active browser sessions — their
 * lifecycle state, ownership, and current page context. Supports
 * both per-session lookup and company-wide session listing.
 */
#[AsCommand(name: 'blb:ai:browser:status')]
class BrowserStatusCommand extends Command
{
    private const NONE_LABEL = '(none)';

    protected $description = 'Show browser session status for a company or specific session';

    protected $signature = 'blb:ai:browser:status
                            {--session= : Specific session ID to inspect}
                            {--company= : Company ID to list active sessions for}';

    public function handle(BrowserSessionManager $manager): int
    {
        $sessionId = $this->option('session');
        $companyId = $this->option('company');

        if ($sessionId !== null) {
            return $this->showSession($manager, $sessionId);
        }

        if ($companyId !== null) {
            return $this->listCompanySessions($manager, (int) $companyId);
        }

        $this->components->error('Provide --session=<id> or --company=<id>.');

        return self::FAILURE;
    }

    private function showSession(BrowserSessionManager $manager, string $sessionId): int
    {
        $state = $manager->getSessionState($sessionId);

        if ($state === null) {
            $this->components->error("Session '{$sessionId}' not found.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Session ID', $state->sessionId);
        $this->components->twoColumnDetail('Status', $state->status->label().' ('.$state->status->value.')');
        $this->components->twoColumnDetail('Agent Employee', (string) $state->agentEmployeeId);
        $this->components->twoColumnDetail('Acting User', (string) ($state->actingForUserId ?? self::NONE_LABEL));
        $this->components->twoColumnDetail('Company', (string) $state->companyId);
        $this->components->twoColumnDetail('Headless', $state->headless ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Current URL', $state->currentUrl ?? self::NONE_LABEL);
        $this->components->twoColumnDetail('Active Tab', $state->activeTabId ?? self::NONE_LABEL);
        $this->components->twoColumnDetail('Tabs', (string) count($state->tabs));
        $this->components->twoColumnDetail('Created', $state->createdAt);
        $this->components->twoColumnDetail('Last Activity', $state->lastActivityAt);
        $this->components->twoColumnDetail('Expires', $state->expiresAt ?? self::NONE_LABEL);

        if ($state->failureReason !== null) {
            $this->components->twoColumnDetail('Failure Reason', $state->failureReason);
        }

        return self::SUCCESS;
    }

    private function listCompanySessions(BrowserSessionManager $manager, int $companyId): int
    {
        $sessions = $manager->getActiveSessionsForCompany($companyId);

        if ($sessions === []) {
            $this->components->info("No active browser sessions for company #{$companyId}.");

            return self::SUCCESS;
        }

        $this->components->info("Active browser sessions for company #{$companyId}:");

        $rows = [];

        foreach ($sessions as $state) {
            $rows[] = [
                $state->sessionId,
                $state->status->label(),
                $state->agentEmployeeId,
                $state->actingForUserId ?? '—',
                $state->headless ? 'H' : 'V',
                $state->currentUrl ?? '—',
                $state->lastActivityAt,
            ];
        }

        $this->table(
            ['Session', 'Status', 'Agent', 'User', 'Mode', 'URL', 'Last Activity'],
            $rows,
        );

        return self::SUCCESS;
    }
}
