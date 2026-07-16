<?php

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\DocumentExtractionException;
use App\Base\Support\Str as BlbStr;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs pdftotext with argument arrays and bounded page/output contracts.
 */
class PdfToTextRunner
{
    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return array{content: string, truncated: bool}
     */
    public function extract(
        string $binary,
        string $pdfPath,
        array $ranges,
        int $timeoutSeconds,
        int $maxChars,
    ): array {
        $content = '';
        $truncated = false;
        $deadline = microtime(true) + $timeoutSeconds;

        foreach ($ranges as [$firstPage, $lastPage]) {
            $remainingSeconds = (int) ceil($deadline - microtime(true));

            if ($remainingSeconds < 1) {
                throw new DocumentExtractionException(
                    'pdf_extraction_timeout',
                    'PDF text extraction exceeded its time limit.',
                );
            }

            $rangeResult = $this->extractRange(
                binary: $binary,
                pdfPath: $pdfPath,
                firstPage: $firstPage,
                lastPage: $lastPage,
                timeoutSeconds: $remainingSeconds,
                maxChars: $maxChars,
            );
            $separator = $content === '' || $rangeResult['content'] === '' ? '' : "\n\n";
            $candidate = $content.$separator.$rangeResult['content'];

            if (mb_strlen($candidate) > $maxChars) {
                $content = BlbStr::truncate($candidate, $maxChars, '');
                $truncated = true;
                break;
            }

            $content = $candidate;
            $truncated = $truncated || $rangeResult['truncated'];

            if ($truncated) {
                break;
            }
        }

        return [
            'content' => trim($content),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{content: string, truncated: bool}
     */
    private function extractRange(
        string $binary,
        string $pdfPath,
        int $firstPage,
        int $lastPage,
        int $timeoutSeconds,
        int $maxChars,
    ): array {
        $process = new Process([
            $binary,
            '-f',
            (string) $firstPage,
            '-l',
            (string) $lastPage,
            '-enc',
            'UTF-8',
            '-layout',
            '-nopgbrk',
            $pdfPath,
            '-',
        ]);
        $process->setTimeout($timeoutSeconds);
        $process->disableOutput();
        $maxBytes = max(4, $maxChars * 4);
        $bytes = '';
        $truncated = false;

        try {
            $process->run(function (string $type, string $chunk) use (
                $process,
                $maxBytes,
                &$bytes,
                &$truncated,
            ): void {
                if ($type !== Process::OUT || $truncated) {
                    return;
                }

                $remaining = $maxBytes + 1 - strlen($bytes);
                $bytes .= substr($chunk, 0, max(0, $remaining));

                if (strlen($bytes) > $maxBytes) {
                    $truncated = true;
                    $process->stop(0);
                }
            });
        } catch (ProcessTimedOutException) {
            throw new DocumentExtractionException(
                'pdf_extraction_timeout',
                'PDF text extraction exceeded its time limit.',
            );
        } catch (ProcessStartFailedException) {
            throw new DocumentExtractionException(
                'pdf_extraction_failed',
                'The PDF text extractor could not be started.',
            );
        } catch (ProcessSignaledException) {
            if (! $truncated) {
                throw new DocumentExtractionException(
                    'pdf_extraction_failed',
                    'The PDF text extractor was interrupted.',
                );
            }
        }

        if (! $process->isSuccessful() && ! $truncated) {
            throw new DocumentExtractionException(
                'pdf_extraction_failed',
                'The PDF text extractor could not read this document.',
            );
        }

        $bytes = mb_strcut($bytes, 0, min(strlen($bytes), $maxBytes), 'UTF-8');

        if (mb_strlen($bytes) > $maxChars) {
            $bytes = BlbStr::truncate($bytes, $maxChars, '');
            $truncated = true;
        }

        return [
            'content' => $bytes,
            'truncated' => $truncated,
        ];
    }
}
