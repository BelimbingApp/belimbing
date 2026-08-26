<?php

/**
 * Settings chrome: Profile / Password / Appearance are route-backed tabs
 * (deep links stay honest), and the sidebar initials circle shares the
 * profile destination with the user name link.
 */
test('settings profile uses route-backed tabs instead of a side menu', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('id="user-settings-tabs"')
        ->toContain('id="user-settings-tabs-tab-profile"')
        ->toContain('id="user-settings-tabs-tab-password"')
        ->toContain('id="user-settings-tabs-tab-appearance"')
        ->toContain(route('profile.edit'))
        ->toContain(route('password.edit'))
        ->toContain(route('appearance.edit'))
        ->not->toContain('md:w-[220px]');
});

test('sidebar initials circles link to the same profile route as the user name', function (): void {
    $user = createAdminUser();

    $html = $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'aria-label="'.e(__('Profile settings')).'"'))
        ->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('href="'.route('profile.edit').'"')
        ->and($html)->toContain($user->initials());
});
