<?php
namespace App\Base\Pdf\Services;

use App\Base\Pdf\Exceptions\PdfRenderException;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\URL;

class PdfRenderer
{
    public function __construct(
        private readonly SignedRenderTokenStore $tokens,
        private readonly PdfArtifactWriter $writer,
        private readonly PlaywrightRunner $runner,
        private readonly ConfigRepository $config,
        private readonly ViewFactory $views,
    ) {}

    /**
     * Render a Blade view through the signed-URL controller, so the view
     * executes inside a BLB request with `auth()->user()` resolved to `$actor`.
     * Use this when the view depends on auth, policies, or request context.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderView(
        string $view,
        array $data,
        ?Authenticatable $actor = null,
        string $templateVersion = 'spike',
        string $dataVersion = 'spike',
    ): PdfArtifact {
        $userId = $actor?->getAuthIdentifier();
        $ttl = (int) $this->config->get('pdf.signed_url_ttl_seconds', 60);

        $tokenId = $this->tokens->issue([
            'view' => $view,
            'data' => $data,
            'user_id' => is_int($userId) ? $userId : null,
            'template_version' => $templateVersion,
            'data_version' => $dataVersion,
        ], $ttl);

        $signedUrl = URL::temporarySignedRoute(
            'blb.pdf.render',
            now()->addSeconds($ttl),
            ['token' => $tokenId],
        );

        $tempPath = tempnam(sys_get_temp_dir(), 'blb_pdf_').'.pdf';

        $result = $this->runner->execute('pdf', [
            'url' => $signedUrl,
            'output_path' => $tempPath,
            'format' => $this->config->get('pdf.paper.format', 'A4'),
            'print_background' => (bool) $this->config->get('pdf.paper.print_background', true),
            'timeout_ms' => (int) $this->config->get('pdf.render_timeout_seconds', 30) * 1000,
        ]);

        if (! ($result['ok'] ?? false)) {
            @unlink($tempPath);
            $reason = $result['error']['message'] ?? 'unknown error';
            throw PdfRenderException::renderFailed($reason);
        }

        return $this->writer->persist(
            sourcePath: $tempPath,
            diskName: (string) $this->config->get('pdf.disk', 'local'),
            directory: (string) $this->config->get('pdf.artifact_directory', 'pdf-artifacts'),
            templateVersion: $templateVersion,
            dataVersion: $dataVersion,
            producedBy: is_int($userId) ? $userId : null,
        );
    }

    /**
     * Render a Blade view inline: PHP renders it to HTML, Chromium loads the
     * HTML via `page.setContent` (no signed URL, no controller dispatch). Use
     * this when the view does not depend on auth/policies/request context.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderInline(
        string $view,
        array $data,
        string $templateVersion = 'spike',
        string $dataVersion = 'spike',
        ?int $producedBy = null,
    ): PdfArtifact {
        $html = $this->views->make($view, $data)->render();
        $tempPath = tempnam(sys_get_temp_dir(), 'blb_pdf_').'.pdf';

        $result = $this->runner->execute('pdf', [
            'html' => $html,
            'output_path' => $tempPath,
            'format' => $this->config->get('pdf.paper.format', 'A4'),
            'print_background' => (bool) $this->config->get('pdf.paper.print_background', true),
            'timeout_ms' => (int) $this->config->get('pdf.render_timeout_seconds', 30) * 1000,
        ]);

        if (! ($result['ok'] ?? false)) {
            @unlink($tempPath);
            $reason = $result['error']['message'] ?? 'unknown error';
            throw PdfRenderException::renderFailed($reason);
        }

        return $this->writer->persist(
            sourcePath: $tempPath,
            diskName: (string) $this->config->get('pdf.disk', 'local'),
            directory: (string) $this->config->get('pdf.artifact_directory', 'pdf-artifacts'),
            templateVersion: $templateVersion,
            dataVersion: $dataVersion,
            producedBy: $producedBy,
        );
    }
}
