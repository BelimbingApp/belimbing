<?php

namespace App\Base\Software\Inventory;

/**
 * A read-only summary of one runtime contribution a host module's extension seam
 * has discovered — for the Software Inventory to report, not to execute.
 *
 * This is deliberately a reporting shape, not a universal adapter runtime: Commerce
 * and Payroll keep their own contracts and know how a contribution actually behaves.
 * A host seam publishes these summaries so the inventory can say "Malaysia payroll
 * rules" or "Shopee channel" without understanding payroll or marketplaces.
 *
 * Immutable value object.
 */
final readonly class ContributionSummary
{
    public const KIND_ADAPTER = 'adapter';

    public const KIND_DATA = 'data';

    public const KIND_READINESS = 'readiness';

    public const KIND_PANEL = 'panel';

    public const KIND_EXPORT = 'export';

    public const KIND_CHANNEL = 'channel';

    /**
     * @param  string  $hostModule  module whose seam discovered this (e.g. people/payroll)
     * @param  string  $seam  seam identifier (e.g. payroll.country-pack)
     * @param  string  $kind  contribution kind — adapter, data, readiness, panel, export, channel, …
     * @param  string  $label  human, domain-specific label shown first (e.g. "Malaysia payroll rules")
     * @param  string  $providerModule  module that provides the contribution; defaults to the host module
     * @param  string  $status  active | inactive | error
     * @param  array<string, scalar>  $metadata  domain detail such as country/channel/version
     */
    public function __construct(
        public string $hostModule,
        public string $seam,
        public string $kind,
        public string $label,
        public string $providerModule = '',
        public string $status = 'active',
        public array $metadata = [],
    ) {}

    /**
     * Module that owns the contribution for inventory attribution: the explicit
     * provider when given, otherwise the host module (a built-in contribution).
     */
    public function attributedModule(): string
    {
        return $this->providerModule !== '' ? $this->providerModule : $this->hostModule;
    }
}
