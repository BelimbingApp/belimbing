<?php

namespace DomainBoundaryFixture\Alpha\Consumer;

use DomainBoundaryFixture\Alpha\Sibling\Models\LocalModel;
use DomainBoundaryFixture\Beta\Provider\Models\ForeignModel;
use DomainBoundaryFixture\Beta\Provider\Services\NotAModel;

final class UsesNullableUnionAndReturn
{
    public function local(LocalModel $model): string
    {
        return 'local';
    }

    public function nonModel(NotAModel $service): string
    {
        return $service->label();
    }

    public function untyped($value): string
    {
        return 'ok';
    }

    public function nullable(?ForeignModel $model): void {}

    public function union(ForeignModel|string $model): void {}

    public function returns(): ForeignModel
    {
        return new ForeignModel;
    }
}
