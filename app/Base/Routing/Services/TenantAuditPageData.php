<?php

namespace App\Base\Routing\Services;

use App\Base\Authz\Middleware\AuthorizeCapability;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\ModuleCheck;
use App\Base\Routing\DomainRouteMiddlewareAudit;
use App\Base\Tenancy\Services\TenantContextMissRecorder;

/**
 * Read-only operator feed for the tenant-audit page.
 *
 * Route rows start from {@see DomainRouteMiddlewareAudit::failures()} (the same
 * findings as `blb:domain-routes --audit`), then the page re-validates the
 * authorization gap with {@see self::routeLacksAuthorizationMiddleware()} so a
 * guard-deletion test can prove that check lives here — not only in the audit
 * class. Ownership rows mirror `blb:module-ownership`.
 */
class TenantAuditPageData
{
    public function __construct(
        private readonly DomainRouteMiddlewareAudit $routeAudit,
        private readonly ModuleCheck $moduleCheck,
        private readonly TenantContextMissRecorder $missRecorder,
    ) {}

    /**
     * @return list<array{
     *     domain: string,
     *     module: string,
     *     uri: string,
     *     name: string|null,
     *     methods: list<string>,
     *     middleware: list<string>,
     *     missing: list<string>,
     *     module_path: string|null,
     *     severity: 'danger'
     * }>
     */
    public function routeRows(): array
    {
        $roots = $this->manifestReader()->moduleRoots();
        $rows = [];

        foreach ($this->routeAudit->failures() as $failure) {
            $missing = [];

            if (in_array('tenant', $failure['missing'], true)) {
                $missing[] = 'tenant';
            }

            // Page-owned authorization presentation check (mutation target for #723).
            if ($this->routeLacksAuthorizationMiddleware($failure['middleware'])) {
                $missing[] = 'authorization';
            }

            if ($missing === []) {
                continue;
            }

            $moduleId = $this->moduleIdForDomainModule($failure['domain'], $failure['module'], $roots);
            $modulePath = $moduleId !== null ? ($roots[$moduleId] ?? null) : null;

            $rows[] = [
                ...$failure,
                'missing' => $missing,
                'module_path' => $this->displayPath($modulePath),
                'severity' => 'danger',
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     modules: list<array{id: string, path: string|null}>,
     *     refusals: list<string>,
     *     ok: bool
     * }
     */
    public function ownershipReport(): array
    {
        $report = $this->moduleCheck->inspectDomainOwnership();
        $roots = $this->manifestReader()->moduleRoots();

        $modules = [];
        foreach ($report['modules'] as $id) {
            $modules[] = [
                'id' => $id,
                'path' => $this->displayPath($roots[$id] ?? null),
            ];
        }

        return [
            'modules' => $modules,
            'refusals' => $report['refusals'],
            'ok' => $report['ok'],
        ];
    }

    /**
     * Last recorded RequireTenantContext misses (route + resolver).
     *
     * @return list<array{route: string|null, resolver: string, at: string}>
     */
    public function missRows(): array
    {
        return $this->missRecorder->recent();
    }

    /**
     * True when the middleware stack has no authz / AuthorizeCapability entry.
     *
     * @param  list<string>  $middleware
     */
    public function routeLacksAuthorizationMiddleware(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'authz' || str_starts_with($entry, 'authz:')) {
                return false;
            }

            if ($entry === AuthorizeCapability::class || str_starts_with($entry, AuthorizeCapability::class.':')) {
                return false;
            }
        }

        return true;
    }

    private function manifestReader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
    }

    /**
     * @param  array<string, string>  $roots
     */
    private function moduleIdForDomainModule(string $domain, string $module, array $roots): ?string
    {
        $needle = strtolower($domain.'/'.$module);
        foreach ($roots as $id => $path) {
            if (strtolower($id) === $needle) {
                return $id;
            }

            $normalized = str_replace('\\', '/', $path);
            if (str_ends_with(strtolower($normalized), '/'.strtolower($domain).'/'.strtolower($module))) {
                return $id;
            }
        }

        return null;
    }

    private function displayPath(?string $absolute): ?string
    {
        if ($absolute === null || $absolute === '') {
            return null;
        }

        $base = str_replace('\\', '/', base_path());
        $path = str_replace('\\', '/', $absolute);

        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
