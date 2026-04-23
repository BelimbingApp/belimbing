<?php

use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const FEATURE_SYSTEM_TEST_TRANSPORT_INDEX_ROUTE = 'admin.system.test-transport.index';
const FEATURE_SYSTEM_TEST_TRANSPORT_STREAM_ROUTE = 'admin.system.test-transport.stream';

beforeEach(function (): void {
    setupAuthzRoles();
});

it('forbids transport test pages and streams without the capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route(FEATURE_SYSTEM_TEST_TRANSPORT_INDEX_ROUTE))->assertForbidden();
    $this->get(route(FEATURE_SYSTEM_TEST_TRANSPORT_STREAM_ROUTE))->assertForbidden();
});

it('renders the transport test page for admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $this->get(route(FEATURE_SYSTEM_TEST_TRANSPORT_INDEX_ROUTE))
        ->assertOk()
        ->assertSee('TestTransport');
});
