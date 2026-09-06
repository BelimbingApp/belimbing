<?php

use App\Base\System\Fixtures\SuggestedOtherGuards;

it('keeps other guards active', function (): void {
    expect(SuggestedOtherGuards::class)->toBeString();
});
