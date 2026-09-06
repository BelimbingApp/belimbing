<?php

namespace DomainBoundaryFixture\Alpha\Consumer;

use DomainBoundaryFixture\Gamma\Bare\Models\BareModel;

final class UsesBareForeignModel
{
    public function describe(BareModel $model): string
    {
        return 'bare';
    }
}
