<?php

namespace App\Base\Settings\Services;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\DTO\ScopeType;
use App\Base\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Collection;

/**
 * Removes complete external-integration setting groups when they contain a
 * secret field, plus any independently stored encrypted setting row.
 */
class CredentialSettingsDevelopmentSanitizer implements DevelopmentSanitizationContributor
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function key(): string
    {
        return 'integration-credentials';
    }

    public function preview(): DevelopmentSanitizationResult
    {
        return $this->result($this->targets()->count());
    }

    public function apply(): DevelopmentSanitizationResult
    {
        $targets = $this->targets();

        foreach ($targets as $setting) {
            $scopeType = $setting->scope_type === null
                ? null
                : ScopeType::tryFrom($setting->scope_type);

            if ($setting->scope_type !== null && ($scopeType === null || $setting->scope_id === null)) {
                $setting->delete();

                continue;
            }

            $scope = match ($scopeType) {
                ScopeType::COMPANY => Scope::company((int) $setting->scope_id),
                ScopeType::EMPLOYEE => new Scope(ScopeType::EMPLOYEE, (int) $setting->scope_id),
                null => null,
            };

            $this->settings->forget($setting->key, $scope);
        }

        return $this->result($targets->count());
    }

    /** @return Collection<int, Setting> */
    private function targets(): Collection
    {
        $groupKeys = $this->credentialGroupKeys();

        return Setting::query()
            ->where(function ($query) use ($groupKeys): void {
                $query->where('is_encrypted', true);

                if ($groupKeys !== []) {
                    $query->orWhereIn('key', $groupKeys);
                }
            })
            ->get();
    }

    /** @return list<string> */
    private function credentialGroupKeys(): array
    {
        $keys = [];

        foreach (config('settings.editable', []) as $group) {
            $fields = is_array($group) && is_array($group['fields'] ?? null)
                ? $group['fields']
                : [];
            $containsSecret = collect($fields)->contains(function (mixed $field): bool {
                return is_array($field)
                    && (($field['encrypted'] ?? false) === true
                        || in_array($field['type'] ?? null, ['password', 'secret'], true));
            });

            if (! $containsSecret) {
                continue;
            }

            foreach ($fields as $field) {
                if (is_array($field) && is_string($field['key'] ?? null)) {
                    $keys[] = $field['key'];
                }
            }
        }

        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }

    private function result(int $affected): DevelopmentSanitizationResult
    {
        return new DevelopmentSanitizationResult(
            key: $this->key(),
            label: __('External integration credentials'),
            affected: $affected,
            detail: __('Remove complete integration setting groups that contain credentials, disabling authenticated scrapers and external connectors.'),
        );
    }
}
