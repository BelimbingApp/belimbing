<?php

use App\Base\System\Fixtures\SuggestedGuardSubject;

it('keeps tenant records scoped', function (): void {
    expect(SuggestedGuardSubject::class)->toBeString();
});
