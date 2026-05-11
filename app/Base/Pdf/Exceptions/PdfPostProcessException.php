<?php
namespace App\Base\Pdf\Exceptions;

use RuntimeException;

class PdfPostProcessException extends RuntimeException
{
    public static function qpdfMissing(string $binary): self
    {
        return new self(
            "qpdf binary not found at [{$binary}]. Install qpdf or set BLB_PDF_QPDF_BINARY. ".
            'See docs/guides/pdf-rendering.md for per-OS install instructions.'
        );
    }

    public static function qpdfFailed(string $stderr, int $exitCode): self
    {
        return new self("qpdf exited with code {$exitCode}: {$stderr}");
    }

    public static function emptyPassword(): self
    {
        return new self('Refusing to protect a PDF with an empty user password.');
    }
}
