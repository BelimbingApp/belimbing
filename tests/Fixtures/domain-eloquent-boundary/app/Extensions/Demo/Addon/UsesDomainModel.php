<?php

namespace DomainBoundaryFixture\Extensions\Demo\Addon;

use DomainBoundaryFixture\Beta\Provider\Models\ForeignModel;

final class UsesDomainModel
{
    public function describe(ForeignModel $model): string
    {
        return $model->label();
    }
}
