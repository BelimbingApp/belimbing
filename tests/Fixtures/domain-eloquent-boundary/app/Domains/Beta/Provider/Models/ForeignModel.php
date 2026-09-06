<?php

namespace DomainBoundaryFixture\Beta\Provider\Models;

use DomainBoundaryFixture\Beta\Provider\Contracts\ForeignContract;
use Illuminate\Database\Eloquent\Model;

final class ForeignModel extends Model implements ForeignContract
{
    public function label(): string
    {
        return (string) $this->getAttribute('label');
    }
}
