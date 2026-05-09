<?php
namespace App\Base\Authz\Database\Seeders;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Exceptions\AuthzRoleCapabilitySeedingException;
use App\Base\Authz\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthzRoleCapabilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Maps role capability keys from config. Uses string keys
     * directly — no capabilities table dependency.
     *
     * Ensures all config-defined roles exist (via AuthzRoleSeeder) before
     * mapping capabilities, so running this seeder alone after config
     * changes is sufficient.
     */
    public function run(): void
    {
        $this->call(AuthzRoleSeeder::class);

        /** @var array<string, array{name: string, description: string|null, grant_all?: bool, capabilities?: array<int, string>}> $roles */
        $roles = config('authz.roles', []);

        /** @var CapabilityRegistry $capabilityRegistry */
        $capabilityRegistry = app(CapabilityRegistry::class);

        foreach ($roles as $roleCode => $roleConfig) {
            $role = Role::query()
                ->whereNull('company_id')
                ->where('code', $roleCode)
                ->first();

            if ($role === null) {
                throw AuthzRoleCapabilitySeedingException::missingRole($roleCode);
            }

            // grant_all roles don't need individual capability rows.
            if ($roleConfig['grant_all'] ?? false) {
                DB::table('base_authz_role_capabilities')
                    ->where('role_id', $role->id)
                    ->delete();

                continue;
            }

            $existingKeys = DB::table('base_authz_role_capabilities')
                ->where('role_id', $role->id)
                ->pluck('capability_key')
                ->all();

            $desiredKeys = [];

            foreach ($roleConfig['capabilities'] ?? [] as $capabilityKey) {
                $capabilityKey = strtolower($capabilityKey);
                $capabilityRegistry->assertKnown($capabilityKey);
                $desiredKeys[] = $capabilityKey;
            }

            $toInsert = array_diff($desiredKeys, $existingKeys);
            $toDelete = array_diff($existingKeys, $desiredKeys);

            if (! empty($toDelete)) {
                DB::table('base_authz_role_capabilities')
                    ->where('role_id', $role->id)
                    ->whereIn('capability_key', $toDelete)
                    ->delete();
            }

            $now = now();

            foreach ($toInsert as $key) {
                DB::table('base_authz_role_capabilities')->insert([
                    'role_id' => $role->id,
                    'capability_key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
