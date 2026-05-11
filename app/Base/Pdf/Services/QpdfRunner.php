<?php
namespace App\Base\Pdf\Services;

use App\Base\Pdf\Exceptions\PdfPostProcessException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class QpdfRunner
{
    public function __construct(
        private readonly ?string $configuredBinary = null,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function isAvailable(): bool
    {
        return $this->resolveBinary() !== null;
    }

    /**
     * Run qpdf with the given arguments. `$arguments` should not include the
     * binary path — it is prepended automatically.
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): void
    {
        $binary = $this->resolveBinary()
            ?? throw PdfPostProcessException::qpdfMissing($this->configuredBinary ?? 'qpdf');

        $process = new Process([$binary, ...$arguments]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            throw PdfPostProcessException::qpdfFailed(
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                $process->getExitCode() ?? -1,
            );
        }
    }

    private function resolveBinary(): ?string
    {
        if ($this->configuredBinary !== null && is_executable($this->configuredBinary)) {
            return $this->configuredBinary;
        }

        $found = (new ExecutableFinder())->find('qpdf');

        return $found !== null && is_executable($found) ? $found : null;
    }
}
