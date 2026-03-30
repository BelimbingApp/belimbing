<?php

use App\Base\Support\AppPath;
use Tests\TestCase;

uses(TestCase::class);

it('converts an app PHP file path to an App class name', function (): void {
    expect(AppPath::toClass(app_path('Base/Support/Str.php')))
        ->toBe('App\Base\Support\Str');
});

it('returns null for paths outside the app directory', function (): void {
    expect(AppPath::toClass(base_path('routes/web.php')))->toBeNull();
});

it('accepts alternate path separators when deriving the class name', function (): void {
    $path = str_replace('/', '\\', app_path('Modules/Core/Company/Models/Company.php'));

    expect(AppPath::toClass($path))
        ->toBe('App\Modules\Core\Company\Models\Company');
});
