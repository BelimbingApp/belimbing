import json
import os
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

from _test_support import run_with_bash_path

SCRIPT = Path(__file__).with_name("claim.sh")
ORIENT = Path(__file__).with_name("orient.sh")


class ClaimOwnLabelTest(unittest.TestCase):
    """#366: an agent's own label is a resume, not a collision.

    Three manual claims in one mission — each forced by claim.sh refusing the
    claimant's own label on a self-filed follow-up — silently skipped every
    invariant the script guarantees, and the first signal was a gate failure
    at merge time.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        env = self.git_env()
        self.bare = base / "canonical.git"
        subprocess.run(["git", "init", "-q", "--bare", str(self.bare)], check=True)
        subprocess.run(
            ["git", "--git-dir", str(self.bare), "symbolic-ref", "HEAD", "refs/heads/main"],
            check=True, env=env,
        )
        seed = base / "seed"
        subprocess.run(["git", "init", "-q", "-b", "main", str(seed)], check=True, env=env)
        (seed / "README").write_text("base\n", encoding="utf-8")
        subprocess.run(["git", "add", "."], cwd=seed, check=True, env=env)
        subprocess.run(["git", "commit", "-q", "-m", "base"], cwd=seed, check=True, env=env)
        subprocess.run(["git", "remote", "add", "origin", str(self.bare)], cwd=seed, check=True, env=env)
        subprocess.run(["git", "push", "-q", "-u", "origin", "main"], cwd=seed, check=True, env=env)

        self.clone = base / "checkout"
        subprocess.run(["git", "clone", "-q", str(self.bare), str(self.clone)], check=True, env=env)

        self.bin = base / "bin"
        self.bin.mkdir()
        gh = self.bin / "gh"
        gh.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail
                case "$1 $2" in
                  "repo view") printf 'example/canonical\\n' ;;
                  "issue view") printf '%s\\n' "$CLAIM_TEST_ISSUE_JSON" ;;
                  "pr list") printf '%s\\n' "${CLAIM_TEST_PR_LIST:-[]}" ;;
                  "label list") printf '[{"name":"agent:fable"}]\\n' ;;
                  "pr create") printf 'https://example/pull/99\\n' ;;
                  "pr edit") exit 0 ;;
                  "issue edit")
                    if printf '%s' "$*" | grep -q -- '--remove-label task:ready'; then
                      exit "${CLAIM_TEST_REMOVE_READY_EXIT:-0}"
                    fi
                    exit 0
                    ;;
                  *) exit 1 ;;
                esac
                """
            ),
            encoding="utf-8",
        )
        gh.chmod(gh.stat().st_mode | stat.S_IXUSR)

    def tearDown(self):
        self.dir.cleanup()

    def git_env(self):
        env = os.environ.copy()
        env.update(
            {
                "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@t",
                "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@t",
            }
        )
        return env

    def run_claim(self, issue_json: dict, agent: str = "fable", pr_list: str = "[]",
                  remove_ready_exit: str = "0"):
        env = self.git_env()
        env["CLAIM_AGENT"] = agent
        env["CLAIM_TEST_ISSUE_JSON"] = json.dumps(issue_json)
        env["CLAIM_TEST_PR_LIST"] = pr_list
        env["CLAIM_TEST_REMOVE_READY_EXIT"] = remove_ready_exit
        return run_with_bash_path(
            ["bash", str(SCRIPT), "42"],
            stub_directory=self.bin,
            env=env,
            cwd=self.clone,
            text=True,
            capture_output=True,
            check=False,
        )

    def issue(self, labels):
        return {
            "state": "OPEN",
            "labels": [{"name": name} for name in labels],
            "title": "self-filed follow-up",
            "url": "https://example/issues/42",
        }

    def test_own_label_without_task_ready_resumes_and_claims(self):
        result = self.run_claim(self.issue(["agent:fable"]))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("resuming #42: it already carries your own label", result.stdout)
        self.assertIn("claimed #42 in draft PR", result.stdout)

    def test_anothers_label_is_still_a_hard_refusal(self):
        result = self.run_claim(self.issue(["agent:sol", "task:ready"]))
        self.assertEqual(result.returncode, 1)
        self.assertIn("already held by agent:sol", result.stderr)

    def test_own_label_with_an_open_claim_pr_is_still_refused_by_the_registry(self):
        pr_list = json.dumps(
            [
                {
                    "number": 88,
                    "title": "self-filed follow-up (#42)",
                    "body": "Closes #42",
                    "headRefName": "agent/fable-issue-42",
                    "labels": [{"name": "agent:fable"}],
                    "url": "https://example/pull/88",
                }
            ]
        )
        result = self.run_claim(self.issue(["agent:fable"]), pr_list=pr_list)
        self.assertEqual(result.returncode, 1)
        self.assertIn("an open PR already holds it", result.stderr)

    def test_unlabelled_issue_still_requires_task_ready(self):
        result = self.run_claim(self.issue([]))
        self.assertEqual(result.returncode, 1)
        self.assertIn("not labelled task:ready", result.stderr)

    def test_failed_task_ready_removal_cannot_abort_a_finished_claim(self):
        result = self.run_claim(self.issue(["agent:fable"]), remove_ready_exit="1")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("claimed #42 in draft PR", result.stdout)


class OrientReviewQueueFilterTest(unittest.TestCase):
    """The bypass-visibility half of #366: the jq filter orient.sh runs over
    open task:review PRs, exercised against fixtures. Extracted from the
    script so the tested program is the shipped program."""

    def extract_filter(self) -> str:
        text = ORIENT.read_text(encoding="utf-8")
        start = text.index("| jq -r '", text.index("review-queue hygiene")) + len("| jq -r '")
        end = text.index("'", start)
        return text[start:end]

    def run_filter(self, prs):
        return subprocess.run(
            ["jq", "-r", self.extract_filter()],
            input=json.dumps(prs),
            text=True,
            capture_output=True,
            check=True,
        ).stdout

    def test_flags_a_task_review_pr_without_a_closing_reference(self):
        out = self.run_filter(
            [
                {"number": 361, "title": "pin LC_ALL", "body": "**From:** fable\n\nready"},
                {"number": 362, "title": "docs", "body": "Closes #358"},
            ]
        )
        self.assertIn("#361 has no Closes #N", out)
        self.assertNotIn("#362", out)

    def test_reports_ok_when_the_queue_is_clean(self):
        out = self.run_filter([{"number": 5, "title": "t", "body": "closes #4"}])
        self.assertIn("ok", out)


if __name__ == "__main__":
    unittest.main()
