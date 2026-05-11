<?php
namespace App\Base\Pdf\Events;

use App\Base\Pdf\Jobs\RenderPdfJob;
use App\Base\Pdf\ValueObjects\PdfArtifact;

class PdfArtifactRendered
{
    public function __construct(
        public readonly RenderPdfJob $request,
        public readonly PdfArtifact $artifact,
    ) {}
}
