<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Concerns;

use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;

trait ManagesSystemAgents
{
    /**
     * Whether this employee is Lara, BLB's system orchestrator Agent.
     */
    public function isLara(): bool
    {
        return $this->id === self::LARA_ID;
    }

    /**
     * Whether this employee is a system Agent.
     *
     * Lara is provisioned at install time and cannot be deleted.
     */
    public function isSystemAgent(): bool
    {
        return $this->isLara();
    }

    /**
     * Whether Lara is provisioned (Employee record exists) and activated
     * (has a resolvable LLM config — either workspace-level or company default).
     *
     * Returns a tri-state: null = not provisioned, false = provisioned but
     * not activated, true = fully activated.
     */
    public static function laraActivationState(): ?bool
    {
        if (! static::query()->whereKey(self::LARA_ID)->exists()) {
            return null;
        }

        $resolver = app(ConfigResolver::class);

        if ($resolver->resolve(self::LARA_ID) !== []) {
            return true;
        }

        return $resolver->resolveDefault(
            Company::LICENSEE_ID,
        ) !== null;
    }

    /**
     * Ensure Lara (the system Agent) exists.
     *
     * Idempotent — safe to call from migrations, setup scripts, and UI.
     * Requires the Licensee company to exist first. Resets the PostgreSQL
     * sequence after explicit-ID insert to avoid auto-increment collisions.
     *
     * @return bool Whether Lara was created (false if already existed or Licensee missing).
     */
    public static function provisionLara(): bool
    {
        if (static::query()->where('id', self::LARA_ID)->exists()) {
            return false;
        }

        if (! Company::query()->where('id', Company::LICENSEE_ID)->exists()) {
            return false;
        }

        static::unguarded(fn () => static::query()->create([
            'id' => self::LARA_ID,
            'company_id' => Company::LICENSEE_ID,
            'employee_type' => 'agent',
            'employee_number' => 'SYS-001',
            'full_name' => 'Lara Belimbing',
            'short_name' => 'Lara',
            'designation' => 'System Assistant',
            'job_description' => 'BLB\'s system Agent. Guides users through setup and onboarding, explains framework architecture and conventions, orchestrates tasks by delegating to specialised Agents, and bootstraps the AI workforce on fresh installs.',
            'status' => 'active',
            'employment_start' => now()->toDateString(),
        ]));

        static::resetSequenceAfterExplicitIdInsert();

        return true;
    }

    /**
     * PostgreSQL sequences do not advance on explicit-ID inserts.
     */
    private static function resetSequenceAfterExplicitIdInsert(): void
    {
        $connection = static::resolveConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            "SELECT setval(pg_get_serial_sequence('employees', 'id'), (SELECT COALESCE(MAX(id), 0) FROM employees))"
        );
    }
}
