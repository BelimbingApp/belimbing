<?php

use Illuminate\Support\Facades\Blade;

it('renders every table container without rounded corners', function (string $container): void {
    $html = Blade::render('<x-ui.table :container="$container" caption="Orders"><tr><td>One</td></tr></x-ui.table>', compact('container'));

    expect($html)->not->toMatch('/\brounded(?:-[\w-]+)?\b/');
})->with(['bordered', 'card', 'flush', 'plain']);
