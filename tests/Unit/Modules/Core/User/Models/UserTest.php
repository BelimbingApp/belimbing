<?php

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Tests\TestCase;

uses(TestCase::class);

it('returns the direct user company id when present', function (): void {
    $user = new User(['company_id' => 11]);
    $user->setRelation('employee', new Employee(['company_id' => 22]));

    expect($user->getCompanyId())->toBe(11);
});

it('falls back to the linked employee company id when user company id is missing', function (): void {
    $user = new User(['company_id' => null]);
    $user->setRelation('employee', new Employee(['company_id' => 22]));

    expect($user->getCompanyId())->toBe(22);
});

it('returns null when neither user nor employee has a company id', function (): void {
    $user = new User(['company_id' => null]);
    $user->setRelation('employee', new Employee(['company_id' => null]));

    expect($user->getCompanyId())->toBeNull();
});
