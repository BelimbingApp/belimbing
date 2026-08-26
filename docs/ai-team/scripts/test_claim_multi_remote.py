import os
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("claim.sh")


class ClaimMultiRemoteTest(unittest.TestCase):
    """Hermetic regressions for claim.sh: multi-remote gh inference and resume."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        self.bare = base / "canonical.git"
        env = self.git_env()
        subprocess.run(["git", "init", "-q", "--bare", str(self.bare)], check=True)
        subprocess.run(
            ["git", "--git-dir", str(self.bare), "symbolic-ref", "HEAD", "refs/heads/main"],
            check=True,
            env=env,
        )

        seed = base / "seed"
        subprocess.run(["git", "init", "-q", "-b", "main", str(seed)], check=True, env=env)
        (seed / "README").write_text("base\n", encoding="utf-8")
        subprocess.run(["git", "add", "."], cwd=seed, check=True, env=env)
        subprocess.run(["git", "commit", "-q", "-m", "base"], cwd=seed, check=True, env=env)
        subprocess.run(
            ["git", "remote", "add", "origin", str(self.bare)],
            cwd=seed,
            check=True,
            env=env,
        )
        subprocess.run(["git", "push", "-q", "-u", "origin", "main"], cwd=seed, check=True, env=env)

        self.clone = base / "checkout"
        subprocess.run(
            ["git", "clone", "-q", str(self.bare), str(self.clone)],
            check=True,
            env=env,
        )
        # Prove the root checkout is a real branch before claiming.
        head = subprocess.run(
            ["git", "rev-parse", "--abbrev-ref", "HEAD"],
            cwd=self.clone,
            check=True,
            capture_output=True,
            text=True,
            env=env,
        ).stdout.strip()
        self.assertEqual(head, "main")

        # Second remote recreates the multi-remote layout that broke gh inference.
        subprocess.run(
            ["git", "remote", "add", "fork", str(self.bare)],
            cwd=self.clone,
            check=True,
            env=env,
        )

        self.bin = base / "bin"
        self.bin.mkdir()
        self.gh = self.bin / "gh"
        self.gh_log = base / "gh.log"
        self.gh.write_text(
            textwrap.dedent(
                f"""\
                #!/usr/bin/env bash
                set -euo pipefail
                log={self.gh_log!s}
                printf '%s\\n' "$*" >>"$log"
                case "$1 $2" in
                  "repo view")
                    printf 'example/canonical\\n'
                    ;;
                  "issue view")
                    printf '%s\\n' '{{"state":"OPEN","labels":[{{"name":"task:ready"}}],"title":"multi-remote claim","url":"https://example/issues/42"}}'
                    ;;
                  "pr list")
                    printf '[]\\n'
                    ;;
                  "label list")
                    printf '[{{"name":"agent:composer"}}]\\n'
                    ;;
                  "pr create")
                    if ! printf '%s' "$*" | grep -q -- '--head'; then
                      echo 'aborted: you must first push the current branch to a remote, or use the --head flag' >&2
                      exit 1
                    fi
                    if ! printf '%s' "$*" | grep -q -- '--repo example/canonical'; then
                      echo 'missing --repo' >&2
                      exit 1
                    fi
                    printf 'https://github.com/example/canonical/pull/99\\n'
                    ;;
                  "pr edit"|"issue edit"|"label create")
                    ;;
                  *)
                    echo "unexpected gh: $*" >&2
                    exit 1
                    ;;
                esac
                """
            ),
            encoding="utf-8",
        )
        self.gh.chmod(self.gh.stat().st_mode | stat.S_IXUSR)

    def tearDown(self):
        if self.clone.exists():
            subprocess.run(
                ["git", "worktree", "prune"],
                cwd=self.clone,
                capture_output=True,
                env=self.git_env(),
            )
        self.dir.cleanup()

    def git_env(self) -> dict[str, str]:
        env = os.environ.copy()
        env.update(
            GIT_TERMINAL_PROMPT="0",
            GIT_ASKPASS=os.devnull,
            GIT_AUTHOR_NAME="claim-test",
            GIT_AUTHOR_EMAIL="claim-test@example.com",
            GIT_COMMITTER_NAME="claim-test",
            GIT_COMMITTER_EMAIL="claim-test@example.com",
        )
        return env

    def run_claim(self, *, worktree: Path, resume_branch: str | None = None) -> subprocess.CompletedProcess[str]:
        env = self.git_env()
        env["PATH"] = f"{self.bin}{os.pathsep}{env.get('PATH', '')}"
        env["CLAIM_AGENT"] = "composer"
        env["CLAIM_WORKTREE"] = str(worktree)
        if resume_branch:
            env["CLAIM_BRANCH"] = resume_branch
        return subprocess.run(
            ["bash", str(SCRIPT), "42"],
            cwd=self.clone,
            env=env,
            capture_output=True,
            text=True,
        )

    def test_claim_passes_head_on_multi_remote_checkout(self):
        worktree = Path(self.dir.name) / "wt-fresh"
        result = self.run_claim(worktree=worktree)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("claimed #42 in draft PR #99", result.stdout)
        self.assertIn(f"worktree: {worktree}", result.stdout)
        self.assertIn("root checkout left on main", result.stdout)
        head = subprocess.run(
            ["git", "rev-parse", "--abbrev-ref", "HEAD"],
            cwd=self.clone,
            check=True,
            capture_output=True,
            text=True,
            env=self.git_env(),
        ).stdout.strip()
        self.assertEqual(head, "main")
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertRegex(log, r"pr create .*--head agent/composer-issue-42")

    def test_claim_without_head_would_have_failed_is_covered_by_stub(self):
        env = self.git_env()
        env["PATH"] = f"{self.bin}{os.pathsep}{env.get('PATH', '')}"
        bad = subprocess.run(
            ["gh", "pr", "create", "--repo", "example/canonical", "--draft", "--title", "x", "--body", "y"],
            cwd=self.clone,
            env=env,
            capture_output=True,
            text=True,
        )
        self.assertNotEqual(bad.returncode, 0)
        self.assertIn("--head flag", bad.stderr)

    def test_resume_opens_pr_when_branch_already_pushed(self):
        branch = "agent/composer-issue-42"
        env = self.git_env()
        subprocess.run(["git", "fetch", "-q", "origin", "main"], cwd=self.clone, check=True, env=env)
        subprocess.run(["git", "branch", branch, "origin/main"], cwd=self.clone, check=True, env=env)
        subprocess.run(["git", "push", "-q", "origin", branch], cwd=self.clone, check=True, env=env)
        worktree = Path(self.dir.name) / "wt-resume"
        result = self.run_claim(worktree=worktree, resume_branch=branch)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("resuming #42", result.stdout)
        self.assertIn("claimed #42 in draft PR #99", result.stdout)


if __name__ == "__main__":
    unittest.main()
