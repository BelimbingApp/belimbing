<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Warns operators when the built frontend assets in public/build are older
 * than their sources (or missing entirely). A stale bundle is a silent
 * failure class: Blade renders against JS globals the served bundle no
 * longer (or does not yet) define, and Alpine-gated chrome like the sidebar
 * menu comes up blank with no error. The in-app deploy rebuilds assets on
 * every update, so this only fires when something sidestepped that flow —
 * a hand-pulled checkout, an aborted build, or a dev box without Vite.
 *
 * Live-resolving on purpose (no caching): the check is a handful of stat()
 * calls, and the diagnostic must disappear the moment a rebuild lands.
 */
final class FrontendBuildStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    /**
     * @param  list<string>|null  $sourcePatterns  glob patterns for the Vite entry sources
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly ?string $manifestPath = null,
        private readonly ?string $hotPath = null,
        private readonly ?string $packageJsonPath = null,
        private readonly ?array $sourcePatterns = null,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canManageUpdates($user)) {
            return [];
        }

        // Default-path mode reads the real working tree, which the test suite
        // must not depend on: a dev box with edited-but-unbuilt JS would fail
        // unrelated tests that count status-bar diagnostics. Tests exercise
        // this provider by injecting explicit paths instead.
        if ($this->manifestPath === null && app()->runningUnitTests()) {
            return [];
        }

        // No frontend to build, or the Vite dev server is serving sources
        // live (the hot file redirects @vite away from public/build).
        if (! is_file($this->packageJsonPath()) || is_file($this->hotPath())) {
            return [];
        }

        $builtAt = $this->fileMtime($this->manifestPath());
        [$newestSourceAt, $newestSource] = $this->newestSource();

        if ($builtAt === null) {
            return [$this->missingBuildDiagnostic()];
        }

        if ($newestSourceAt !== null && $newestSourceAt > $builtAt) {
            return [$this->staleBuildDiagnostic($builtAt, $newestSourceAt, $newestSource)];
        }

        return [];
    }

    private function missingBuildDiagnostic(): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frontend-build.missing',
            severity: StatusVariant::Error,
            source: __('Updates'),
            summary: __('Frontend assets have not been built'),
            detail: __('No built frontend bundle exists and no Vite dev server is running, so pages load without working interface code. Use Rebuild assets on the Updates page.'),
            target: $this->updatesUrl(),
        );
    }

    private function staleBuildDiagnostic(int $builtAt, int $newestSourceAt, ?string $newestSource): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frontend-build.stale',
            severity: StatusVariant::Warning,
            source: __('Updates'),
            summary: __('Frontend assets are older than their sources'),
            detail: __('The served interface bundle was built before the newest frontend source changes, so pages may run outdated code and Alpine-driven chrome can fail silently. Use Rebuild assets on the Updates page.'),
            target: $this->updatesUrl(),
            metadata: array_filter([
                'built_at' => Carbon::createFromTimestamp($builtAt)->utc()->toIso8601String(),
                'newest_source_at' => Carbon::createFromTimestamp($newestSourceAt)->utc()->toIso8601String(),
                'newest_source' => $newestSource,
            ]),
        );
    }

    /**
     * @return array{0: int|null, 1: string|null} newest mtime and its file
     */
    private function newestSource(): array
    {
        $newestAt = null;
        $newestFile = null;

        foreach ($this->sourcePatterns() as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $mtime = $this->fileMtime($file);

                if ($mtime !== null && ($newestAt === null || $mtime > $newestAt)) {
                    $newestAt = $mtime;
                    $newestFile = str_replace('\\', '/', $file);
                }
            }
        }

        if ($newestFile !== null) {
            $base = str_replace('\\', '/', base_path()).'/';
            $newestFile = str_starts_with($newestFile, $base)
                ? substr($newestFile, strlen($base))
                : $newestFile;
        }

        return [$newestAt, $newestFile];
    }

    /**
     * @return list<string>
     */
    private function sourcePatterns(): array
    {
        // The Vite entries and everything they import statically — see
        // vite.config.js input and resources/core/js/app.js. Blade templates
        // are deliberately excluded: statting hundreds of views per render
        // isn't worth catching a missing Tailwind utility, while a JS/CSS
        // contract mismatch is the class that blanks the UI.
        return $this->sourcePatterns ?? [
            base_path('resources/core/js/*.js'),
            base_path('resources/app.css'),
            base_path('resources/core/css/*.css'),
            base_path('bun.lock'),
            base_path('package.json'),
        ];
    }

    private function manifestPath(): string
    {
        return $this->manifestPath ?? public_path('build/manifest.json');
    }

    private function hotPath(): string
    {
        return $this->hotPath ?? public_path('hot');
    }

    private function packageJsonPath(): string
    {
        return $this->packageJsonPath ?? base_path('package.json');
    }

    private function fileMtime(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime === false ? null : $mtime;
    }

    private function updatesUrl(): ?string
    {
        return Route::has('admin.system.software.updates.index')
            ? route('admin.system.software.updates.index')
            : null;
    }

    private function canManageUpdates(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.software.updates.manage')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
