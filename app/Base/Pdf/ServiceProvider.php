<?php
namespace App\Base\Pdf;

use App\Base\Pdf\Services\PdfPostProcessor;
use App\Base\Pdf\Services\PdfRenderer;
use App\Base\Pdf\Services\QpdfRunner;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/pdf.php', 'pdf');

        $this->app->singleton(PdfRenderer::class);
        $this->app->singleton(QpdfRunner::class, function ($app) {
            $config = $app->make(ConfigRepository::class);
            return new QpdfRunner(
                configuredBinary: $config->get('pdf.qpdf.binary'),
                timeoutSeconds: (int) $config->get('pdf.qpdf.timeout_seconds', 60),
            );
        });
        $this->app->singleton(PdfPostProcessor::class);
    }
}
