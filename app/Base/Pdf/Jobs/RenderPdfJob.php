<?php

namespace App\Base\Pdf\Jobs;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Base\Pdf\Services\PdfPostProcessor;
use App\Base\Pdf\Services\PdfRenderer;
use App\Modules\Core\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

class RenderPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MODE_INLINE = 'inline';

    public const MODE_VIEW = 'view';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata  Free-form payload passed through to PdfArtifactRendered listeners (e.g. payroll_run_id, employee_id) so they can route the artifact without re-deriving it.
     */
    public function __construct(
        public readonly string $view,
        public readonly array $data,
        public readonly ?int $actorUserId = null,
        public readonly string $templateVersion = 'spike',
        public readonly string $dataVersion = 'spike',
        public readonly ?string $password = null,
        public readonly string $renderMode = self::MODE_INLINE,
        public readonly array $metadata = [],
    ) {}

    public function handle(PdfRenderer $renderer, PdfPostProcessor $postProcessor): void
    {
        $actor = $this->actorUserId !== null ? User::query()->find($this->actorUserId) : null;

        $artifact = $this->renderMode === self::MODE_VIEW
            ? $renderer->renderView(
                view: $this->view,
                data: $this->data,
                actor: $actor,
                templateVersion: $this->templateVersion,
                dataVersion: $this->dataVersion,
            )
            : $renderer->renderInline(
                view: $this->view,
                data: $this->data,
                templateVersion: $this->templateVersion,
                dataVersion: $this->dataVersion,
                producedBy: $this->actorUserId,
            );

        if ($this->password !== null && $this->password !== '') {
            $artifact = $postProcessor->protectWithPassword($artifact, $this->password);
        }

        Event::dispatch(new PdfArtifactRendered($this, $artifact));
    }
}
