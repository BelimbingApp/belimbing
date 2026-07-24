<?php

namespace App\Base\Database\Contracts;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProgress;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;

interface DataShareMirrorEngine
{
    public const CONTAINER_TAG = 'data-share-mirror-engine';

    public function mode(): string;

    public function execute(DataShareMirrorReview $review, ?DataShareMirrorProgress $progress = null): DataShareMirrorExecutionResult;
}
