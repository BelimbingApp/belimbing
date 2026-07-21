<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Exceptions\SupabaseMirrorSetupException;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SupabaseMirrorSetupService
{
    public const ACCESS_TOKEN_SETTING = 'data_share.mirror.supabase.access_token';

    public const PROJECT_REF_SETTING = 'data_share.mirror.supabase.project_ref';

    public const PROJECT_NAME_SETTING = 'data_share.mirror.supabase.project_name';

    public const ORGANIZATION_SETTING = 'data_share.mirror.supabase.organization_slug';

    public const REGION_SETTING = 'data_share.mirror.supabase.region';

    public function __construct(
        private readonly SupabaseMirrorManagementClient $supabase,
        private readonly SettingsService $settings,
        private readonly DataShareMirrorManager $mirror,
        private readonly DataShareMirrorProviderInitializer $initializer,
    ) {}

    /**
     * @return array{organizations: list<array{id: string, slug: string, name: string}>, projects: list<array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}>}
     */
    public function discover(string $accessToken): array
    {
        return $this->supabase->discover($accessToken);
    }

    public function rememberAccessToken(string $accessToken): void
    {
        DB::transaction(function () use ($accessToken): void {
            $settings = $this->lockedAccessTokenSettings();

            // Normalize any legacy duplicate global rows before writing. Global
            // scope columns are nullable, so their composite unique index alone
            // cannot guarantee uniqueness on every supported database.
            if ($settings->count() > 1) {
                $this->settings->forget(self::ACCESS_TOKEN_SETTING);
            }

            $this->settings->set(self::ACCESS_TOKEN_SETTING, trim($accessToken), encrypted: true);
        });
    }

    public function savedAccessToken(): ?string
    {
        $accessToken = $this->settings->get(self::ACCESS_TOKEN_SETTING);

        return is_string($accessToken) && trim($accessToken) !== '' ? trim($accessToken) : null;
    }

    public function forgetAccessToken(): void
    {
        $this->settings->forget(self::ACCESS_TOKEN_SETTING);
    }

    public function forgetAccessTokenIfMatches(string $attemptedToken): bool
    {
        return DB::transaction(function () use ($attemptedToken): bool {
            $settings = $this->lockedAccessTokenSettings();

            if ($settings->isEmpty()) {
                return false;
            }

            foreach ($settings as $setting) {
                if (! $setting->is_encrypted) {
                    return false;
                }

                $savedToken = json_decode(Crypt::decryptString($setting->value), true);

                if (! is_string($savedToken) || ! hash_equals(trim($attemptedToken), trim($savedToken))) {
                    return false;
                }
            }

            $this->settings->forget(self::ACCESS_TOKEN_SETTING);

            return true;
        });
    }

    /** @return Collection<int, Setting> */
    private function lockedAccessTokenSettings(): Collection
    {
        return Setting::query()
            ->where('key', self::ACCESS_TOKEN_SETTING)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->lockForUpdate()
            ->get();
    }

    public function useExistingProject(string $accessToken, string $projectRef, string $databasePassword): DataShareMirrorConnectionStatus
    {
        $project = $this->supabase->project($accessToken, $projectRef);
        [$url, $status] = $this->firstReachableConnection(
            $this->supabase->connectionUrls($accessToken, $project, $databasePassword),
        );

        if ($url === null || ! $status->reachable) {
            throw SupabaseMirrorSetupException::databaseUnavailable($status->message);
        }

        if (! $status->available && ! $status->initializable) {
            throw SupabaseMirrorSetupException::databaseUnavailable($status->message);
        }

        $this->persist($project, $url);

        return $this->initializeWhenNeeded($status);
    }

    public function createDedicatedProject(
        string $accessToken,
        string $organizationSlug,
        string $name,
        string $regionGroup,
    ): DataShareMirrorConnectionStatus {
        $databasePassword = Str::password(48);
        $project = $this->supabase->createProject(
            $accessToken,
            $organizationSlug,
            $name,
            $regionGroup,
            $databasePassword,
        );
        $urls = $this->supabase->connectionUrls($accessToken, $project, $databasePassword);

        // Project creation is the irreversible boundary in this workflow. Save
        // the generated password inside the encrypted URL before any network
        // preflight can fail, otherwise a successfully created project could be
        // left with an unrecoverable credential.
        $this->persist($project, $urls[0]);

        try {
            [$reachableUrl, $status] = $this->firstReachableConnection($urls);
        } catch (Throwable $exception) {
            return $this->createdProjectPendingStatus(
                DataShareMirrorException::unexpected('supabase_connect', $exception)->getMessage(),
            );
        }

        if ($reachableUrl !== null && $reachableUrl !== $urls[0]) {
            $this->persist($project, $reachableUrl);
        }

        if (! $status->reachable) {
            return $status;
        }

        if (! $status->available && ! $status->initializable) {
            throw SupabaseMirrorSetupException::databaseUnavailable($status->message);
        }

        return $this->initializeWhenNeeded($status);
    }

    public function finish(): DataShareMirrorConnectionStatus
    {
        $status = $this->mirror->status();

        if ($status->available) {
            return $status;
        }

        if (! $status->reachable || ! $status->initializable) {
            throw SupabaseMirrorSetupException::databaseUnavailable($status->message);
        }

        $this->initializer->initialize();

        return $this->mirror->status();
    }

    public function forgetProjectMetadata(): void
    {
        foreach ([
            self::ACCESS_TOKEN_SETTING,
            self::PROJECT_REF_SETTING,
            self::PROJECT_NAME_SETTING,
            self::ORGANIZATION_SETTING,
            self::REGION_SETTING,
        ] as $key) {
            $this->settings->forget($key);
        }
    }

    /**
     * @param  non-empty-list<string>  $urls
     * @return array{0: string|null, 1: DataShareMirrorConnectionStatus}
     */
    private function firstReachableConnection(array $urls): array
    {
        $lastStatus = null;

        foreach ($urls as $url) {
            $status = $this->mirror->testConnection($url, 'supabase');
            $lastStatus = $status;

            if ($status->reachable) {
                return [$url, $status];
            }
        }

        if (! $lastStatus instanceof DataShareMirrorConnectionStatus) {
            throw SupabaseMirrorSetupException::invalidResponse();
        }

        return [null, $lastStatus];
    }

    /** @param array{ref: string, name: string, organization_slug: string, region: string} $project */
    private function persist(array $project, string $url): void
    {
        $this->settings->set(DataShareMirrorConnectionManager::PROVIDER_SETTING_KEY, 'supabase');
        $this->settings->set(DataShareMirrorConnectionManager::SETTING_KEY, $url, encrypted: true);
        $this->settings->set(self::PROJECT_REF_SETTING, $project['ref']);
        $this->settings->set(self::PROJECT_NAME_SETTING, $project['name']);

        if ($project['organization_slug'] !== '') {
            $this->settings->set(self::ORGANIZATION_SETTING, $project['organization_slug']);
        }

        if ($project['region'] !== '') {
            $this->settings->set(self::REGION_SETTING, $project['region']);
        }

        $this->mirror->disconnect();
    }

    private function initializeWhenNeeded(DataShareMirrorConnectionStatus $status): DataShareMirrorConnectionStatus
    {
        if ($status->initializable) {
            $this->initializer->initialize();

            return $this->mirror->status();
        }

        return $status;
    }

    private function createdProjectPendingStatus(?string $error = null): DataShareMirrorConnectionStatus
    {
        return new DataShareMirrorConnectionStatus(
            configured: true,
            available: false,
            reachable: false,
            driver: 'pgsql',
            localRole: null,
            remoteRole: null,
            serverVersion: null,
            pgDumpVersion: null,
            psqlVersion: null,
            reasonCode: 'project_provisioning',
            message: $error === null
                ? __('The Supabase project was created and its encrypted connection was saved, but the database is still provisioning and cannot be checked yet.')
                : __('The Supabase project was created and its encrypted connection was saved. Database verification then failed: :error', ['error' => $error]),
            providerKey: 'supabase',
            providerLabel: __('Supabase'),
            localDriver: null,
            transferMode: null,
        );
    }
}
