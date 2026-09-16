<?php

namespace App\Base\Workflow\Human\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Human\DTO\HumanActionOutcome;
use App\Base\Workflow\Human\DTO\HumanActionRequest;
use Illuminate\Database\Eloquent\Model;

interface HumanActionHandler
{
    public function handle(Actor $actor, Model $subject, HumanActionRequest $request): HumanActionOutcome;
}
