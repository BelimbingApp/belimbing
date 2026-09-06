<?php

use App\Base\System\Exceptions\GuardMutationException;
use App\Base\System\Services\ReviewMutationEvidence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $root = storage_path('framework/testing/review-mutation-evidence-'.bin2hex(random_bytes(4)));
    $this->evidenceRoot = $root;
    $this->evidenceRemote = $root.'/remote.git';
    $this->evidenceCheckout = $root.'/checkout';

    File::ensureDirectoryExists($root);
    Process::run(['git', 'init', '--bare', $this->evidenceRemote])->throw();
    Process::run(['git', 'init', '--initial-branch=main', $this->evidenceCheckout])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'config', 'user.email', 'test@example.com'])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'config', 'user.name', 'Evidence Test'])->throw();
    file_put_contents($this->evidenceCheckout.'/tracked.txt', "tracked\n");
    Process::path($this->evidenceCheckout)->run(['git', 'add', 'tracked.txt'])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'commit', '-m', 'fixture'])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'remote', 'add', 'origin', $this->evidenceRemote])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'push', 'origin', 'HEAD:refs/heads/main'])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'push', 'origin', 'HEAD:refs/pull/42/head'])->throw();
});

afterEach(function (): void {
    File::deleteDirectory($this->evidenceRoot);
});

it('renders a gate-parsable marker block for a clean exact PR head', function (): void {
    $output = app(ReviewMutationEvidence::class)->render(
        $this->evidenceCheckout,
        42,
        'desktop-sol',
        "**Mutation evidence (batch)**\n\n| Mutation | Before | After | Restored |\n",
    );

    $lines = explode("\n", $output);
    expect($lines[0])->toBe('**From:** desktop-sol')
        ->and($lines[2])->toMatch('/^\*\*HEAD reviewed:\*\* [0-9a-f]{40}$/')
        ->and($lines[4])->toBe('**Verdict:** <accept|changes required>')
        ->and($output)->toContain('**Mutation evidence (batch)**');
});

it('refuses a dirty checkout before rendering evidence', function (): void {
    file_put_contents($this->evidenceCheckout.'/tracked.txt', "dirty\n");

    expect(fn () => app(ReviewMutationEvidence::class)->render(
        $this->evidenceCheckout,
        42,
        'desktop-sol',
        "**Mutation evidence (batch)**\n",
    ))->toThrow(GuardMutationException::class, 'checkout is dirty');
});

it('refuses a checkout whose head differs from the fetched PR head and names both SHAs', function (): void {
    $prHead = trim(Process::path($this->evidenceCheckout)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    file_put_contents($this->evidenceCheckout.'/tracked.txt', "next\n");
    Process::path($this->evidenceCheckout)->run(['git', 'add', 'tracked.txt'])->throw();
    Process::path($this->evidenceCheckout)->run(['git', 'commit', '-m', 'local-only'])->throw();
    $checkoutHead = trim(Process::path($this->evidenceCheckout)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());

    expect(fn () => app(ReviewMutationEvidence::class)->render(
        $this->evidenceCheckout,
        42,
        'desktop-sol',
        "**Mutation evidence (batch)**\n",
    ))->toThrow(
        GuardMutationException::class,
        "checkout HEAD {$checkoutHead} does not match PR #42 head {$prHead}",
    );
});
