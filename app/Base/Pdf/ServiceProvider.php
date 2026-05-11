<?php
namespace App\Base\Pdf;

use App\Base\Pdf\Services\PdfRenderer;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/pdf.php', 'pdf');

        $this->app->singleton(PdfRenderer::class);
    }
}
