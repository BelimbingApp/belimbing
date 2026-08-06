<?php

use App\Base\Database\Concerns\ReplaysAfterIncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    use ReplaysAfterIncubatingSchema;

    /**
     * Columns whose values are executable PHP class identities.
     *
     * @var array<string, list<string>>
     */
    private const FQCN_COLUMNS = [
        'addressables' => ['addressable_type'],
        'ai_operation_dispatches' => ['entity_type'],
        'base_audit_mutations' => ['auditable_type'],
        'base_authz_decision_logs' => ['resource_type'],
        'base_integration_outbound_exchanges' => ['owner_type', 'error_class'],
        'base_workflow' => ['model_class'],
        'base_workflow_process_runs' => ['subject_type'],
        'base_workflow_status_transitions' => ['guard_class', 'action_class'],
        'notifications' => ['type', 'notifiable_type'],
        'operation_quality_action_items' => ['actionable_type'],
        'operation_quality_evidence' => ['evidenceable_type'],
        'people_notification_delivery_logs' => ['notifiable_type'],
    ];

    /**
     * Registry provenance columns that may contain a full or relative source path.
     *
     * @var array<string, list<string>>
     */
    private const PROVENANCE_COLUMNS = [
        'base_database_tables' => ['module_path', 'migration_file'],
        'base_database_migration_sources' => ['relative_path', 'migration_file'],
    ];

    /**
     * Kiat-owned persisted references that may point back into its source checkout.
     *
     * @var array<string, list<string>>
     */
    private const KIAT_REFERENCE_COLUMNS = [
        'kiat_investment_agent_tasks' => ['prompt'],
        'kiat_investment_export_quarter_signals' => ['raw_reference'],
        'kiat_investment_globalwits_source_updates' => ['raw_reference'],
        'kiat_investment_klse_screener_snapshots' => ['raw_reference'],
        'kiat_investment_maybank_snapshots' => ['raw_reference'],
        'kiat_investment_source_captures' => ['raw_reference'],
        'kiat_investment_trade_observations' => ['raw_reference'],
    ];

    private const LANDING_SETTING_KEY = 'ui.landing_menu_id';

    private const DEPLOYMENT_RUN_SETTING_KEY = 'system.update.deployment.last_run';

    private const OLD_SOFTWARE_MENU_ID = 'admin.system.software.modules';

    private const NEW_SOFTWARE_MENU_ID = 'admin.system.software.domains';

    private const OLD_SOFTWARE_PATH = 'admin/system/software/modules';

    private const NEW_SOFTWARE_PATH = 'admin/system/software/domains';

    /** @var array<string, string>|null */
    private ?array $extensionPathReplacements = null;

    /** @var array<string, string>|null */
    private ?array $sourceKeyReplacements = null;

    public function up(): void
    {
        $this->normalizeSeederRegistry();
        $this->normalizeTopologyColumns(self::FQCN_COLUMNS);
        $this->normalizeTopologyColumns(self::PROVENANCE_COLUMNS);
        $this->normalizeJsonPayloadColumn('base_workflow_transition_outbox', 'payload');
        $this->normalizeJsonPayloadColumn('jobs', 'payload');
        $this->normalizeJsonPayloadColumn('failed_jobs', 'payload');
        $this->normalizeKiatReferences();
        $this->normalizeLandingSettings();
        $this->normalizeDeploymentRunSettings();
        $this->normalizeSoftwarePins();
        $this->normalizeCapabilityAssignments(
            'base_authz_role_capabilities',
            ['role_id'],
        );
        $this->normalizeCapabilityAssignments(
            'base_authz_principal_capabilities',
            ['company_id', 'principal_type', 'principal_id'],
        );
        $this->normalizeTopologyColumns([
            'base_workflow_status_transitions' => ['capability'],
        ]);
    }

    /**
     * The cutover is intentionally forward-only. Reintroducing legacy identities
     * would make applied migrations, queued work, and polymorphic rows invalid.
     */
    public function down(): void {}

    private function normalizeSeederRegistry(): void
    {
        $table = 'base_database_seeders';

        if (! $this->hasColumns($table, ['id', 'seeder_class'])) {
            return;
        }

        $columns = ['id', 'seeder_class'];

        foreach (['module_path', 'migration_file', 'status', 'ran_at', 'error_message'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $columns[] = $column;
            }
        }

        DB::transaction(function () use ($table, $columns): void {
            $rows = DB::table($table)->select($columns)->orderBy('id')->get();

            foreach ($rows as $row) {
                if (! DB::table($table)->where('id', $row->id)->exists()) {
                    continue;
                }

                $newClass = $this->normalizeTopologyString((string) $row->seeder_class);
                $updates = ['seeder_class' => $newClass];

                foreach (['module_path', 'migration_file'] as $column) {
                    if (in_array($column, $columns, true) && is_string($row->{$column} ?? null)) {
                        $updates[$column] = $this->normalizeTopologyString($row->{$column});
                    }
                }

                $changed = $newClass !== (string) $row->seeder_class
                    || collect($updates)->contains(
                        fn (mixed $value, string $column): bool => $column !== 'seeder_class'
                            && $value !== ($row->{$column} ?? null),
                    );

                if (! $changed) {
                    continue;
                }

                $canonical = DB::table($table)
                    ->where('seeder_class', $newClass)
                    ->where('id', '!=', $row->id)
                    ->first($columns);

                if ($canonical === null) {
                    DB::table($table)->where('id', $row->id)->update($updates);

                    continue;
                }

                if ($this->seederTerminalPriority($row) > $this->seederTerminalPriority($canonical)) {
                    DB::table($table)->where('id', $canonical->id)->delete();
                    DB::table($table)->where('id', $row->id)->update($updates);

                    continue;
                }

                $canonicalUpdates = ['seeder_class' => $newClass];

                foreach (['module_path', 'migration_file'] as $column) {
                    if (! in_array($column, $columns, true)) {
                        continue;
                    }

                    $value = $canonical->{$column} ?? ($row->{$column} ?? null);

                    if (is_string($value)) {
                        $canonicalUpdates[$column] = $this->normalizeTopologyString($value);
                    }
                }

                DB::table($table)->where('id', $canonical->id)->update($canonicalUpdates);
                DB::table($table)->where('id', $row->id)->delete();
            }
        });
    }

    private function seederTerminalPriority(object $row): int
    {
        return match ($row->status ?? null) {
            'completed' => 2,
            'skipped' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string, list<string>>  $columnsByTable
     */
    private function normalizeTopologyColumns(array $columnsByTable): void
    {
        foreach ($columnsByTable as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $values = DB::table($table)
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column);

                foreach ($values as $value) {
                    if (! is_string($value)) {
                        continue;
                    }

                    $normalized = $this->normalizeTopologyString($value);

                    if ($normalized !== $value) {
                        DB::table($table)->where($column, $value)->update([$column => $normalized]);
                    }
                }
            }
        }
    }

    private function normalizeJsonPayloadColumn(string $table, string $column): void
    {
        if (! $this->hasColumns($table, ['id', $column])) {
            return;
        }

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    [$decoded, $valid] = $this->decodeJson($row->{$column}, associative: false);

                    if (! $valid) {
                        continue;
                    }

                    [$normalized, $changed] = $this->normalizePayloadValue($decoded);

                    if ($changed) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => $this->encodeJson($normalized),
                        ]);
                    }
                }
            }, 'id');
    }

    private function normalizeKiatReferences(): void
    {
        foreach (self::KIAT_REFERENCE_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $values = DB::table($table)
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column);

                foreach ($values as $value) {
                    if (! is_string($value)) {
                        continue;
                    }

                    $normalized = $this->normalizeKiatReference($value);

                    if ($normalized !== $value) {
                        DB::table($table)->where($column, $value)->update([$column => $normalized]);
                    }
                }
            }
        }
    }

    private function normalizeLandingSettings(): void
    {
        if (! $this->hasColumns('base_settings', ['id', 'key', 'value'])) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::LANDING_SETTING_KEY)
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            [$value, $valid] = $this->decodeJson($row->value);

            if (! $valid || $value !== self::OLD_SOFTWARE_MENU_ID) {
                continue;
            }

            DB::table('base_settings')->where('id', $row->id)->update([
                'value' => $this->encodeJson(self::NEW_SOFTWARE_MENU_ID),
            ]);
        }
    }

    private function normalizeDeploymentRunSettings(): void
    {
        if (! $this->hasColumns('base_settings', ['id', 'key', 'value'])) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::DEPLOYMENT_RUN_SETTING_KEY)
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            [$value, $valid] = $this->decodeJson($row->value);

            if (! $valid || ! is_array($value) || ! is_array($value['target_keys'] ?? null)) {
                continue;
            }

            $normalizedKeys = array_map(
                fn (mixed $key): mixed => is_string($key) ? $this->normalizeSourceKey($key) : $key,
                $value['target_keys'],
            );

            if ($normalizedKeys === $value['target_keys']) {
                continue;
            }

            $value['target_keys'] = array_values($normalizedKeys);

            DB::table('base_settings')->where('id', $row->id)->update([
                'value' => $this->encodeJson($value),
            ]);
        }
    }

    private function normalizeSoftwarePins(): void
    {
        $table = 'user_pins';

        if (! $this->hasColumns($table, ['id', 'user_id', 'url', 'url_hash'])) {
            return;
        }

        $columns = ['id', 'user_id', 'url', 'url_hash'];

        if (Schema::hasColumn($table, 'label')) {
            $columns[] = 'label';
        }

        $pins = DB::table($table)->select($columns)->orderBy('id')->get();

        foreach ($pins as $pin) {
            if (! is_string($pin->url)
                || ! str_contains($pin->url, self::OLD_SOFTWARE_PATH)) {
                continue;
            }

            $url = str_replace(self::OLD_SOFTWARE_PATH, self::NEW_SOFTWARE_PATH, $pin->url);
            $urlHash = $this->pinUrlHash($url);
            $updates = [
                'url' => $url,
                'url_hash' => $urlHash,
            ];

            if (in_array('label', $columns, true) && ($pin->label ?? null) === 'Modules') {
                $updates['label'] = 'Domains';
            }

            $duplicateColumns = ['id'];

            if (in_array('label', $columns, true)) {
                $duplicateColumns[] = 'label';
            }

            $duplicate = DB::table($table)
                ->where('user_id', $pin->user_id)
                ->where('url_hash', $urlHash)
                ->where('id', '!=', $pin->id)
                ->first($duplicateColumns);

            if ($duplicate !== null) {
                if (in_array('label', $columns, true) && ($duplicate->label ?? null) === 'Modules') {
                    DB::table($table)->where('id', $duplicate->id)->update(['label' => 'Domains']);
                }

                DB::table($table)->where('id', $pin->id)->delete();

                continue;
            }

            DB::table($table)->where('id', $pin->id)->update($updates);
        }
    }

    /**
     * @param  list<string>  $identityColumns
     */
    private function normalizeCapabilityAssignments(string $table, array $identityColumns): void
    {
        $columns = ['id', 'capability_key', ...$identityColumns];

        if (! $this->hasColumns($table, $columns)) {
            return;
        }

        $rows = DB::table($table)->select($columns)->orderBy('id')->get();

        foreach ($rows as $row) {
            if (! is_string($row->capability_key)
                || ! str_contains($row->capability_key, self::OLD_SOFTWARE_MENU_ID)) {
                continue;
            }

            $capability = str_replace(
                self::OLD_SOFTWARE_MENU_ID,
                self::NEW_SOFTWARE_MENU_ID,
                $row->capability_key,
            );
            $duplicate = DB::table($table)
                ->where('capability_key', $capability)
                ->where('id', '!=', $row->id);

            foreach ($identityColumns as $column) {
                $value = $row->{$column};
                $duplicate = $value === null
                    ? $duplicate->whereNull($column)
                    : $duplicate->where($column, $value);
            }

            if ($duplicate->exists()) {
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'capability_key' => $capability,
            ]);
        }
    }

    private function normalizeTopologyString(string $value): string
    {
        $normalized = $this->normalizeSourceKey($value);
        $normalized = str_replace(
            [
                'App\\Modules\\Core\\',
                'App\\Modules\\',
                'app/Modules/Core/',
                'app/Modules/',
                'app\\Modules\\Core\\',
                'app\\Modules\\',
                self::OLD_SOFTWARE_MENU_ID,
                self::OLD_SOFTWARE_PATH,
            ],
            [
                'App\\Core\\',
                'App\\Domains\\',
                'app/Core/',
                'app/Domains/',
                'app\\Core\\',
                'app\\Domains\\',
                self::NEW_SOFTWARE_MENU_ID,
                self::NEW_SOFTWARE_PATH,
            ],
            $normalized,
        );

        // A previous development run could have applied the legacy Extension
        // replacement to an already-canonical class. Collapse that residue,
        // then map only a true top-level legacy Extensions namespace.
        $normalized = preg_replace_callback(
            '/(?<![A-Za-z0-9_\\\\])(?:App\\\\){2,}Extensions\\\\/',
            static fn (): string => 'App\\Extensions\\',
            $normalized,
        ) ?? $normalized;
        $normalized = preg_replace_callback(
            '/(?<![A-Za-z0-9_\\\\])Extensions\\\\/',
            static fn (): string => 'App\\Extensions\\',
            $normalized,
        ) ?? $normalized;

        if ($normalized === 'App\\Modules\\Core') {
            $normalized = 'App\\Core';
        } elseif ($normalized === 'App\\Modules') {
            $normalized = 'App\\Domains';
        } elseif ($normalized === 'Extensions') {
            $normalized = 'App\\Extensions';
        } elseif ($normalized === 'app/Modules/Core') {
            $normalized = 'app/Core';
        } elseif ($normalized === 'app/Modules') {
            $normalized = 'app/Domains';
        }

        foreach ($this->extensionPathMap() as $legacy => $canonical) {
            $normalized = str_replace($legacy, $canonical, $normalized);
        }

        return $this->normalizeUnmappedExtensionPath($normalized);
    }

    private function normalizeKiatReference(string $value): string
    {
        $normalized = $this->normalizeTopologyString($value);
        $normalized = preg_replace(
            '#(?<![A-Za-z0-9_-])investment/#',
            'Investment/',
            $normalized,
        ) ?? $normalized;

        return preg_replace(
            '#(?<![A-Za-z0-9_-])investment\\\\#',
            'Investment\\',
            $normalized,
        ) ?? $normalized;
    }

    /**
     * @return array{mixed, bool}
     */
    private function normalizePayloadValue(mixed $value): array
    {
        if (is_string($value)) {
            $normalized = $this->looksSerialized($value)
                ? $this->normalizeSerializedValue($value)
                : $this->normalizeTopologyString($value);

            return [$normalized, $normalized !== $value];
        }

        if (is_array($value)) {
            $changed = false;

            foreach ($value as $key => $item) {
                [$value[$key], $itemChanged] = $this->normalizePayloadValue($item);
                $changed = $changed || $itemChanged;
            }

            return [$value, $changed];
        }

        if ($value instanceof stdClass) {
            $changed = false;

            foreach ($value as $key => $item) {
                [$value->{$key}, $itemChanged] = $this->normalizePayloadValue($item);
                $changed = $changed || $itemChanged;
            }

            return [$value, $changed];
        }

        return [$value, false];
    }

    private function looksSerialized(string $value): bool
    {
        return preg_match('/^(?:N;|[aObisdCE]:)/', $value) === 1;
    }

    /**
     * Rewrite PHP's length-prefixed string/class tokens without instantiating
     * queued objects. Blind replacement would invalidate serialized lengths.
     */
    private function normalizeSerializedValue(string $serialized): string
    {
        $length = strlen($serialized);
        $cursor = 0;
        $output = '';

        while ($cursor < $length) {
            $type = $serialized[$cursor];

            if (! in_array($type, ['s', 'O', 'C', 'E'], true)
                || ($serialized[$cursor + 1] ?? null) !== ':') {
                $output .= $serialized[$cursor];
                $cursor++;

                continue;
            }

            $digitsStart = $cursor + 2;
            $digitsEnd = $digitsStart;

            while ($digitsEnd < $length && ctype_digit($serialized[$digitsEnd])) {
                $digitsEnd++;
            }

            if ($digitsEnd === $digitsStart
                || substr($serialized, $digitsEnd, 2) !== ':"') {
                $output .= $serialized[$cursor];
                $cursor++;

                continue;
            }

            $declaredLength = (int) substr($serialized, $digitsStart, $digitsEnd - $digitsStart);
            $contentStart = $digitsEnd + 2;
            $contentEnd = $contentStart + $declaredLength;

            if ($contentEnd >= $length || $serialized[$contentEnd] !== '"') {
                $output .= $serialized[$cursor];
                $cursor++;

                continue;
            }

            $content = substr($serialized, $contentStart, $declaredLength);
            $normalizedContent = $this->normalizeTopologyString($content);

            if ($type === 'C') {
                $custom = $this->normalizeCustomSerializedObject(
                    $serialized,
                    $contentEnd + 1,
                    $normalizedContent,
                );

                if ($custom !== null) {
                    $output .= $custom['value'];
                    $cursor = $custom['cursor'];

                    continue;
                }
            }

            $output .= $type.':'.strlen($normalizedContent).':"'.$normalizedContent.'"';
            $cursor = $contentEnd + 1;
        }

        return $output;
    }

    /**
     * @return array{value: string, cursor: int}|null
     */
    private function normalizeCustomSerializedObject(
        string $serialized,
        int $cursor,
        string $normalizedClass,
    ): ?array {
        $length = strlen($serialized);

        if (($serialized[$cursor] ?? null) !== ':') {
            return null;
        }

        $digitsStart = $cursor + 1;
        $digitsEnd = $digitsStart;

        while ($digitsEnd < $length && ctype_digit($serialized[$digitsEnd])) {
            $digitsEnd++;
        }

        if ($digitsEnd === $digitsStart || substr($serialized, $digitsEnd, 2) !== ':{') {
            return null;
        }

        $declaredLength = (int) substr($serialized, $digitsStart, $digitsEnd - $digitsStart);
        $contentStart = $digitsEnd + 2;
        $contentEnd = $contentStart + $declaredLength;

        if ($contentEnd >= $length || $serialized[$contentEnd] !== '}') {
            return null;
        }

        $content = substr($serialized, $contentStart, $declaredLength);
        $normalizedContent = $this->normalizeTopologyString($content);

        return [
            'value' => 'C:'.strlen($normalizedClass).':"'.$normalizedClass.'":'
                .strlen($normalizedContent).':{'.$normalizedContent.'}',
            'cursor' => $contentEnd + 1,
        ];
    }

    /**
     * @return array{mixed, bool}
     */
    private function decodeJson(mixed $value, bool $associative = true): array
    {
        if (is_array($value) || is_scalar($value) || $value === null) {
            if (! is_string($value)) {
                return [$value, true];
            }

            try {
                return [json_decode($value, $associative, flags: JSON_THROW_ON_ERROR), true];
            } catch (JsonException) {
                return [null, false];
            }
        }

        return [null, false];
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalizeSourceKey(string $value): string
    {
        if (isset($this->softwareSourceKeyMap()[$value])) {
            return $this->softwareSourceKeyMap()[$value];
        }

        if (preg_match('/^app-(?:Modules|Domains)-([^-]+)(?:-(.+))?$/', $value, $matches) === 1) {
            $domain = Str::kebab($matches[1]);

            return isset($matches[2])
                ? 'module-'.$domain.'-'.Str::kebab($matches[2])
                : 'domain-'.$domain;
        }

        if (preg_match('/^app-Extensions-(.+)$/', $value, $matches) === 1) {
            return 'extension-'.Str::kebab($matches[1]);
        }

        if (preg_match('/^extensions-(.+)$/', $value, $matches) === 1) {
            return 'extension-'.Str::kebab($matches[1]);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function softwareSourceKeyMap(): array
    {
        if ($this->sourceKeyReplacements !== null) {
            return $this->sourceKeyReplacements;
        }

        $map = [];

        foreach (glob(app_path('Domains/*'), GLOB_ONLYDIR) ?: [] as $domainPath) {
            $domain = basename($domainPath);
            $domainKey = Str::kebab($domain);
            $map['app-Modules-'.$domain] = 'domain-'.$domainKey;
            $map['app-Domains-'.$domain] = 'domain-'.$domainKey;

            foreach (glob($domainPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                $module = basename($modulePath);

                if (str_starts_with($module, '.')) {
                    continue;
                }

                $moduleKey = 'module-'.$domainKey.'-'.Str::kebab($module);
                $map['app-Modules-'.$domain.'-'.$module] = $moduleKey;
                $map['app-Domains-'.$domain.'-'.$module] = $moduleKey;
            }
        }

        foreach (glob(app_path('Extensions/*'), GLOB_ONLYDIR) ?: [] as $extensionPath) {
            $extension = basename($extensionPath);

            if (str_starts_with($extension, '.')) {
                continue;
            }

            $extensionKey = Str::kebab($extension);
            $canonical = 'extension-'.$extensionKey;
            $map['extensions-'.$extensionKey] = $canonical;
            $map['app-Extensions-'.$extension] = $canonical;

            foreach (glob($extensionPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                if (! is_file($modulePath.DIRECTORY_SEPARATOR.'ServiceProvider.php')) {
                    continue;
                }

                $module = basename($modulePath);
                $canonicalModule = $canonical.'-'.Str::kebab($module);
                $map['extensions-'.$extensionKey.'-'.Str::kebab($module)] = $canonicalModule;
                $map['app-Extensions-'.$extension.'-'.$module] = $canonicalModule;
            }
        }

        return $this->sourceKeyReplacements = $map;
    }

    /**
     * @return array<string, string>
     */
    private function extensionPathMap(): array
    {
        if ($this->extensionPathReplacements !== null) {
            return $this->extensionPathReplacements;
        }

        $map = [];

        foreach (glob(app_path('Extensions/*'), GLOB_ONLYDIR) ?: [] as $extensionPath) {
            $extension = basename($extensionPath);

            if (str_starts_with($extension, '.')) {
                continue;
            }

            $legacyRoot = 'extensions/'.Str::kebab($extension);
            $canonicalRoot = 'app/Extensions/'.$extension;
            $map[$legacyRoot] = $canonicalRoot;
            $map[str_replace('/', '\\', $legacyRoot)] = str_replace('/', '\\', $canonicalRoot);

            foreach (glob($extensionPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                if (! is_file($modulePath.DIRECTORY_SEPARATOR.'ServiceProvider.php')) {
                    continue;
                }

                $module = basename($modulePath);
                $legacyModule = $legacyRoot.'/'.Str::kebab($module);
                $canonicalModule = $canonicalRoot.'/'.$module;
                $map[$legacyModule] = $canonicalModule;
                $map[str_replace('/', '\\', $legacyModule)] = str_replace('/', '\\', $canonicalModule);
            }
        }

        uksort(
            $map,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        return $this->extensionPathReplacements = $map;
    }

    private function normalizeUnmappedExtensionPath(string $value): string
    {
        $normalized = preg_replace_callback(
            '#(?<![A-Za-z0-9_])extensions/([a-z0-9][a-z0-9-]*)(?:/([a-z0-9][a-z0-9-]*))?#',
            static function (array $matches): string {
                $path = 'app/Extensions/'.Str::studly($matches[1]);

                return isset($matches[2]) ? $path.'/'.Str::studly($matches[2]) : $path;
            },
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '#(?<![A-Za-z0-9_])extensions\\\\([a-z0-9][a-z0-9-]*)(?:\\\\([a-z0-9][a-z0-9-]*))?#',
            static function (array $matches): string {
                $path = 'app\\Extensions\\'.Str::studly($matches[1]);

                return isset($matches[2]) ? $path.'\\'.Str::studly($matches[2]) : $path;
            },
            $normalized,
        ) ?? $normalized;
    }

    private function pinUrlHash(string $url): string
    {
        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            $parts = false;
        }

        if ($parts === false) {
            return md5(trim($url));
        }

        $path = isset($parts['path']) && is_string($parts['path'])
            ? trim($parts['path'])
            : '/';

        if ($path === '') {
            $path = '/';
        } elseif (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = $parts['query'] ?? null;

        if (! is_string($query) || $query === '') {
            return md5($path);
        }

        $parameters = [];
        parse_str($query, $parameters);
        $this->sortRecursive($parameters);

        return md5($path.'?'.http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986,
        ));
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
