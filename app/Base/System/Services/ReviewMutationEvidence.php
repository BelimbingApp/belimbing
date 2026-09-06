<?php

namespace App\Base\System\Services;

use App\Base\System\Exceptions\GuardMutationException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Binds mutation evidence to one clean checkout and its fetched pull-request head.
 */
final class ReviewMutationEvidence
{
    public function render(string $checkout, int $pullRequest, string $agent, string $batchMarkdown): string
    {
        return $this->format($this->verify($checkout, $pullRequest), $agent, $batchMarkdown);
    }

    public function verify(string $checkout, int $pullRequest): string
    {
        if ($pullRequest < 1) {
            throw new GuardMutationException('Evidence requires a positive pull request number.');
        }

        $status = $this->git($checkout, ['status', '--porcelain', '--untracked-files=all']);
        if (trim($status->output()) !== '') {
            throw new GuardMutationException('Cannot render review evidence: checkout is dirty.');
        }

        $checkoutHead = $this->sha($this->git($checkout, ['rev-parse', 'HEAD'])->output(), 'checkout HEAD');
        $this->git($checkout, ['fetch', '--quiet', '--no-tags', 'origin', "refs/pull/{$pullRequest}/head"]);
        $pullRequestHead = $this->sha(
            $this->git($checkout, ['rev-parse', 'FETCH_HEAD'])->output(),
            "PR #{$pullRequest} head",
        );

        if ($checkoutHead !== $pullRequestHead) {
            throw new GuardMutationException(
                "Cannot render review evidence: checkout HEAD {$checkoutHead} does not match PR #{$pullRequest} head {$pullRequestHead}.",
            );
        }

        return $checkoutHead;
    }

    public function format(string $head, string $agent, string $batchMarkdown): string
    {
        $head = $this->sha($head, 'reviewed HEAD');
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $agent) !== 1) {
            throw new GuardMutationException('CLAIM_AGENT must be a bare lowercase agent identity before rendering evidence.');
        }

        return "**From:** {$agent}\n\n"
            ."**HEAD reviewed:** {$head}\n\n"
            ."**Verdict:** <accept|changes required>\n\n"
            .ltrim($batchMarkdown);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $checkout, array $arguments): ProcessResult
    {
        $result = Process::path($checkout)->run(['git', ...$arguments]);
        if ($result->failed()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());

            throw new GuardMutationException('Git evidence check failed: '.($detail ?: 'unknown git error'));
        }

        return $result;
    }

    private function sha(string $value, string $label): string
    {
        $sha = trim($value);
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new GuardMutationException("Git evidence check returned an invalid {$label} [{$sha}].");
        }

        return $sha;
    }
}
