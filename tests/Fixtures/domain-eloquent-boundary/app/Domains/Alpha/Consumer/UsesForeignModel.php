<?php

namespace DomainBoundaryFixture\Alpha\Consumer;

use DomainBoundaryFixture\Beta\Provider\Models\ForeignModel;

final class UsesForeignModel
{
    public function describe(ForeignModel $model): string
    {
        return $model->label();
    }
}
