<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Modules\Core\AI\Tools\VisibleNavMenuSnapshotTool;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $tool = new VisibleNavMenuSnapshotTool($menu);

        $this->assertToolMetadata(
            $tool,
            'visible_nav_menu',
            null,
            ['filter'],
            [],
        );
    });
});

describe('execution', function () {
    it('returns error when no user is authenticated', function () {
        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $menu->shouldNotReceive('snapshotForUser');

        $this->tool = new VisibleNavMenuSnapshotTool($menu);

        $out = (string) $this->tool->execute([]);

        expect($out)->toContain('Error');
        expect($out)->toContain('No authenticated user');
    });

    it('returns sorted paths derived from full URLs', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $menu->shouldReceive('snapshotForUser')
            ->once()
            ->with(Mockery::on(fn ($u) => $u->is($user)))
            ->andReturn([
                'filtered' => collect([]),
                'flat' => [
                    'b' => [
                        'label' => 'Beta Page',
                        'pinLabel' => 'beta',
                        'icon' => 'heroicon-o-squares-2x2',
                        'href' => 'https://app.test/beta-path',
                        'route' => 'beta.route',
                    ],
                    'a' => [
                        'label' => 'Alpha Page',
                        'pinLabel' => 'alpha',
                        'icon' => 'heroicon-o-squares-2x2',
                        'href' => 'https://app.test/alpha-path',
                        'route' => 'alpha.route',
                    ],
                ],
            ]);

        $this->tool = new VisibleNavMenuSnapshotTool($menu);
        $data = $this->decodeToolExecution([]);

        expect($data['truncated'])->toBeFalse()
            ->and($data['total_matched'])->toBe(2)
            ->and($data['returned'])->toBe(2)
            ->and($data['items'][0]['label'])->toBe('Alpha Page')
            ->and($data['items'][0]['path'])->toBe('/alpha-path')
            ->and($data['items'][1]['label'])->toBe('Beta Page')
            ->and($data['items'][1]['path'])->toBe('/beta-path');
    });

    it('filters by label or path substring', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $menu->shouldReceive('snapshotForUser')->once()->andReturn([
            'filtered' => collect([]),
            'flat' => [
                'keep' => [
                    'label' => 'Postcodes',
                    'pinLabel' => 'postcodes',
                    'icon' => 'i',
                    'href' => '/admin/geonames/postcodes',
                    'route' => 'admin.geonames.postcodes',
                ],
                'drop' => [
                    'label' => 'Users',
                    'pinLabel' => 'users',
                    'icon' => 'i',
                    'href' => '/admin/users',
                    'route' => 'admin.users.index',
                ],
            ],
        ]);

        $this->tool = new VisibleNavMenuSnapshotTool($menu);
        $data = $this->decodeToolExecution(['filter' => 'postcode']);

        expect($data['total_matched'])->toBe(1)
            ->and($data['items'])->toHaveCount(1)
            ->and($data['items'][0]['path'])->toBe('/admin/geonames/postcodes');
    });

    it('truncates when more than 200 entries match', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $flat = [];
        for ($i = 0; $i < 201; $i++) {
            $flat['item_'.$i] = [
                'label' => 'Item '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'pinLabel' => 'x',
                'icon' => 'i',
                'href' => '/x/'.$i,
                'route' => 'r.'.$i,
            ];
        }

        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $menu->shouldReceive('snapshotForUser')->once()->andReturn([
            'filtered' => collect([]),
            'flat' => $flat,
        ]);

        $this->tool = new VisibleNavMenuSnapshotTool($menu);
        $data = $this->decodeToolExecution([]);

        expect($data['total_matched'])->toBe(201)
            ->and($data['returned'])->toBe(200)
            ->and($data['truncated'])->toBeTrue();
    });

    it('skips entries without a usable root-relative path', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $menu = Mockery::mock(NavigableMenuSnapshot::class);
        $menu->shouldReceive('snapshotForUser')->once()->andReturn([
            'filtered' => collect([]),
            'flat' => [
                'bad' => [
                    'label' => 'No path',
                    'pinLabel' => 'x',
                    'icon' => 'i',
                    'href' => null,
                    'route' => null,
                ],
                'good' => [
                    'label' => 'Ok',
                    'pinLabel' => 'ok',
                    'icon' => 'i',
                    'href' => '/ok',
                    'route' => 'ok.route',
                ],
            ],
        ]);

        $this->tool = new VisibleNavMenuSnapshotTool($menu);
        $data = $this->decodeToolExecution([]);

        expect($data['items'])->toHaveCount(1)
            ->and($data['items'][0]['path'])->toBe('/ok');
    });
});
