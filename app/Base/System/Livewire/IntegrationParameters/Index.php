<?php

namespace App\Base\System\Livewire\IntegrationParameters;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\SearchablePaginatedList;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Operator-managed secrets for external integrations: each
 * entry is a key-value pair under `integrations.<system>.<name>` at the
 * global settings layer. Definition-owned encryption makes every value
 * write-only; operators may replace a secret but cannot read it back.
 *
 * Module-owned configuration (e.g. eBay OAuth credentials) stays on its
 * module settings page next to its diagnostics — this list is for
 * cross-cutting integration parameters with no dedicated home (Cloudflare
 * API token, WeChat ingest, the legacy AX SQL account, …). The free-text
 * description lives in a plain sibling setting (`<key>.description`) so the
 * parameter key itself stays a directly consumable value.
 */
class Index extends SearchablePaginatedList
{
    use InteractsWithNotifications;

    protected const string VIEW_NAME = 'livewire.admin.system.integration-parameters.index';

    protected const string VIEW_DATA_KEY = 'parameters';

    protected const string SORT_COLUMN = 'key';

    protected const array SEARCH_COLUMNS = ['key'];

    private const KEY_PREFIX = 'integrations.';

    private const DESCRIPTION_SUFFIX = '.description';

    /**
     * x-ui.secret-input's default saved-value sentinel: with has-value the
     * field renders this mask, and an untouched save submits it (or nothing).
     * Either means "keep the stored secret".
     */
    private const SECRET_KEPT_SENTINEL = '******';

    public bool $addModalOpen = false;

    public string $newSystem = '';

    public string $newName = '';

    public string $newDescription = '';

    public string $newValue = '';

    public bool $entryModalOpen = false;

    public ?string $entryKey = null;

    public string $entryDescription = '';

    public string $entryValue = '';

    public function openAddModal(): void
    {
        $this->authorizeManage();

        $this->reset('newSystem', 'newName', 'newDescription', 'newValue');
        $this->resetErrorBag();
        $this->addModalOpen = true;
    }

    public function addParameter(SettingsService $settings): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'newSystem' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            // 'description' is reserved: the free-text description is stored as
            // a `<key>.description` sibling setting and would collide.
            'newName' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'not_in:description'],
            'newDescription' => ['nullable', 'string', 'max:500'],
            'newValue' => ['required', 'string', 'max:5000'],
        ], [
            'newSystem.regex' => __('Lowercase letters, digits, dashes, and underscores only.'),
            'newName.regex' => __('Lowercase letters, digits, dashes, and underscores only.'),
        ]);

        $key = self::KEY_PREFIX.$validated['newSystem'].'.'.$validated['newName'];

        if ($this->settingRow($key) !== null) {
            $this->addError('newName', __('“:key” already exists — open it in the list to edit.', ['key' => $key]));

            return;
        }

        $settings->set($key, $validated['newValue']);

        if (trim((string) $validated['newDescription']) !== '') {
            $settings->set($key.self::DESCRIPTION_SUFFIX, trim((string) $validated['newDescription']));
        }

        $this->addModalOpen = false;

        $this->notify(__('Secret stored encrypted as :key.', ['key' => $key]));
    }

    public function openEntry(string $key, SettingsService $settings): void
    {
        $this->authorizeManage();

        $row = $this->settingRow($key);

        if ($row === null) {
            return;
        }

        $this->entryKey = $key;
        $this->entryDescription = (string) $settings->get($key.self::DESCRIPTION_SUFFIX);
        // Secrets are write-only: blank means keep the current value.
        $this->entryValue = '';
        $this->resetErrorBag();
        $this->entryModalOpen = true;
    }

    public function saveEntry(SettingsService $settings): void
    {
        $this->authorizeManage();

        $row = $this->settingRow((string) $this->entryKey);

        if ($row === null) {
            $this->closeEntry();

            return;
        }

        $validated = $this->validate([
            'entryValue' => ['nullable', 'string', 'max:5000'],
            'entryDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $value = trim((string) ($validated['entryValue'] ?? ''));
        $secretUntouched = $value === '' || $value === self::SECRET_KEPT_SENTINEL;

        if ($value !== '' && ! $secretUntouched) {
            $settings->set($row->key, $validated['entryValue']);
        }

        if (trim((string) ($validated['entryDescription'] ?? '')) !== '') {
            $settings->set($row->key.self::DESCRIPTION_SUFFIX, trim((string) $validated['entryDescription']));
        } else {
            $settings->forget($row->key.self::DESCRIPTION_SUFFIX);
        }

        $key = $row->key;
        $this->closeEntry();
        $this->notify(__('Parameter :key updated.', ['key' => $key]));
    }

    public function deleteParameter(SettingsService $settings): void
    {
        $this->authorizeManage();

        $key = (string) $this->entryKey;

        if (! str_starts_with($key, self::KEY_PREFIX) || $this->settingRow($key) === null) {
            $this->closeEntry();

            return;
        }

        $settings->forget($key);
        $settings->forget($key.self::DESCRIPTION_SUFFIX);

        $this->closeEntry();
        $this->notify(__('Parameter :key deleted.', ['key' => $key]));
    }

    public function closeEntry(): void
    {
        $this->entryModalOpen = false;
        $this->entryKey = null;
        $this->entryDescription = '';
        $this->entryValue = '';
        $this->resetErrorBag();
    }

    /**
     * Row presentation for the table: type + displayable value + description.
     *
     * @return array{display: string, description: string}
     */
    public function rowData(Setting $row): array
    {
        $settings = app(SettingsService::class);

        return [
            'display' => __('Stored securely'),
            'description' => (string) $settings->get($row->key.self::DESCRIPTION_SUFFIX),
        ];
    }

    public function entryIsSecret(): bool
    {
        return true;
    }

    protected function query(): EloquentBuilder
    {
        return Setting::query()
            ->whereNull('scope_type')
            ->where('key', 'like', self::KEY_PREFIX.'%')
            ->where('key', 'not like', '%'.self::DESCRIPTION_SUFFIX);
    }

    protected function sortableColumns(): array
    {
        return [
            'key' => 'key',
            'updated_at' => 'updated_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'key' => 'asc',
            'updated_at' => 'desc',
        ];
    }

    protected function defaultSortDir(): string
    {
        return 'asc';
    }

    private function settingRow(string $key): ?Setting
    {
        return Setting::query()
            ->whereNull('scope_type')
            ->where('key', $key)
            ->first();
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'admin.system.integration-parameters.manage',
        );
    }
}
