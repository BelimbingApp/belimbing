<?php
namespace App\Base\Pdf\Services;

use App\Base\Pdf\Exceptions\PdfPostProcessException;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

class PdfPostProcessor
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly QpdfRunner $qpdf,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Return a new PdfArtifact whose bytes are the source artifact encrypted
     * with the given user/owner passwords (AES-256). The original artifact
     * remains on disk untouched.
     *
     * @throws PdfPostProcessException
     */
    public function protectWithPassword(
        PdfArtifact $artifact,
        string $userPassword,
        ?string $ownerPassword = null,
    ): PdfArtifact {
        if ($userPassword === '') {
            throw PdfPostProcessException::emptyPassword();
        }

        $disk = $this->filesystem->disk($artifact->disk);
        $contents = $disk->get($artifact->path);
        if ($contents === null) {
            throw new PdfPostProcessException("Source artifact missing: {$artifact->disk}:{$artifact->path}");
        }

        $sourceTemp = tempnam(sys_get_temp_dir(), 'blb_qpdf_in_').'.pdf';
        $outputTemp = tempnam(sys_get_temp_dir(), 'blb_qpdf_out_').'.pdf';
        file_put_contents($sourceTemp, $contents);

        try {
            $this->qpdf->run([
                '--encrypt',
                $userPassword,
                $ownerPassword ?? $userPassword,
                '256',
                '--',
                $sourceTemp,
                $outputTemp,
            ]);

            $encrypted = file_get_contents($outputTemp);
            if ($encrypted === false || $encrypted === '') {
                throw PdfPostProcessException::qpdfFailed('qpdf produced an empty output file', -1);
            }
        } finally {
            @unlink($sourceTemp);
        }

        $sha256 = hash('sha256', $encrypted);
        $now = new DateTimeImmutable();
        $directory = (string) $this->config->get('pdf.artifact_directory', 'pdf-artifacts');
        $protectedPath = trim($directory, '/').'/protected/'.$now->format('Y/m/d').'/'.$sha256.'.pdf';

        $disk->put($protectedPath, $encrypted);
        @unlink($outputTemp);

        return new PdfArtifact(
            disk: $artifact->disk,
            path: $protectedPath,
            templateVersion: $artifact->templateVersion,
            dataVersion: $artifact->dataVersion.' (encrypted)',
            bytes: strlen($encrypted),
            sha256: $sha256,
            producedBy: $artifact->producedBy,
            producedAt: $now,
        );
    }
}
