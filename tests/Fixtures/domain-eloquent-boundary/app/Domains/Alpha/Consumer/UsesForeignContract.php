<?php

namespace DomainBoundaryFixture\Alpha\Consumer;

use DomainBoundaryFixture\Beta\Provider\Contracts\ForeignContract;

final class UsesForeignContract
{
    public function describe(ForeignContract $model): string
    {
        return $model->label();
    }
}
