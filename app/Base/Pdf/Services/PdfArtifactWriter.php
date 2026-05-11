<?php
namespace App\Base\Pdf\Services;

use App\Base\Pdf\Exceptions\PdfRenderException;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

class PdfArtifactWriter
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function persist(
        string $sourcePath,
        string $diskName,
        string $directory,
        string $templateVersion,
        string $dataVersion,
        ?int $producedBy,
    ): PdfArtifact {
        if (! is_file($sourcePath)) {
            throw PdfRenderException::artifactMissing($sourcePath);
        }

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            throw PdfRenderException::renderFailed('unable to read produced artifact');
        }

        $sha256 = hash('sha256', $contents);
        $now = new DateTimeImmutable();
        $relativePath = trim($directory, '/').'/'.$now->format('Y/m/d').'/'.$sha256.'.pdf';

        $disk = $this->filesystem->disk($diskName);
        $disk->put($relativePath, $contents);

        @unlink($sourcePath);

        return new PdfArtifact(
            disk: $diskName,
            path: $relativePath,
            templateVersion: $templateVersion,
            dataVersion: $dataVersion,
            bytes: strlen($contents),
            sha256: $sha256,
            producedBy: $producedBy,
            producedAt: $now,
        );
    }
}
