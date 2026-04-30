<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Menu\MenuBuilder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

test('ai admin menu is hidden when the user lacks AI admin capabilities', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $snapshot = app(NavigableMenuSnapshot::class)->snapshotForUser($user);
    $flat = $snapshot['flat'];
    $tree = app(MenuBuilder::class)->build($snapshot['filtered']);

    expect($flat)->not->toHaveKeys([
        'ai.lara',
        'ai.providers',
        'ai.pricing-overrides',
        'ai.tools',
        'ai.control-plane',
    ]);
    expect(flattenMenuTreeIds($tree))->not->toContain('ai');
});

test('ai operators see the full AI admin menu', function (): void {
    $user = createAiMenuTestUserWithRole('ai_operator');

    $snapshot = app(NavigableMenuSnapshot::class)->snapshotForUser($user);
    $flat = $snapshot['flat'];
    $tree = app(MenuBuilder::class)->build($snapshot['filtered']);

    expect($flat)->toHaveKeys([
        'ai.lara',
        'ai.providers',
        'ai.pricing-overrides',
        'ai.tools',
        'ai.control-plane',
    ]);
    expect(flattenMenuTreeIds($tree))->toContain('ai');
});

function createAiMenuTestUserWithRole(string $roleCode): User
{
    setupAuthzRoles();

    $role = Role::query()->where('code', $roleCode)->whereNull('company_id')->firstOrFail();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/**
 * @param  array<int, array{item: object, children: array}>  $tree
 * @return list<string>
 */
function flattenMenuTreeIds(array $tree): array
{
    $ids = [];

    foreach ($tree as $node) {
        $ids[] = $node['item']->id;

        foreach (flattenMenuTreeIds($node['children']) as $childId) {
            $ids[] = $childId;
        }
    }

    return $ids;
}
