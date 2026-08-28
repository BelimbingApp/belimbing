import os
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

from _test_support import bash_path, run_with_bash_path

SCRIPT = Path(__file__).with_name("hold.sh")


class HoldTestCase(unittest.TestCase):
    """Shared gh-stub harness for hold.sh — no test_ methods of its own."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        self.bin = base / "bin"
        self.bin.mkdir()
        self.gh_log = base / "gh.log"
        self.existing_labels = base / "existing-labels.json"
        self.existing_labels.write_text("[]", encoding="utf-8")
        self.comment_body = base / "comment-body.txt"
        gh = self.bin / "gh"
        gh.write_text(
            textwrap.dedent(
                f"""\
                #!/usr/bin/env bash
                set -euo pipefail
                log="$HOLD_TEST_GH_LOG"
                labels_path="$HOLD_TEST_LABELS"
                printf '%s\\n' "$*" >>"$log"
                case "$1 $2" in
                  "repo view")
                    printf 'example/canonical\\n'
                    ;;
                  "pr view")
                    jq -n --arg state "${{HOLD_TEST_STATE:-OPEN}}" '{{number: 7, state: $state}}'
                    ;;
                  "label list")
                    cat "$labels_path"
                    ;;
                  "label create")
                    name="$3"
                    jq --arg n "$name" '. + [{{"name": $n}}]' "$labels_path" >"$labels_path.tmp"
                    mv "$labels_path.tmp" "$labels_path"
                    ;;
                  "pr edit")
                    ;;
                  "pr comment")
                    prev=""
                    for arg in "$@"; do
                      if [ "$prev" = "--body-file" ]; then
                        cp "$arg" "$HOLD_TEST_COMMENT"
                      fi
                      prev="$arg"
                    done
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
        gh.chmod(gh.stat().st_mode | stat.S_IXUSR)
        self.cwd = base

    def tearDown(self):
        self.dir.cleanup()

    def run_hold(
        self, *args: str, agent: str = "sol", state: str = "OPEN"
    ) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env["HOLD_TEST_GH_LOG"] = bash_path(self.gh_log)
        env["HOLD_TEST_LABELS"] = bash_path(self.existing_labels)
        env["HOLD_TEST_COMMENT"] = bash_path(self.comment_body)
        env["HOLD_TEST_STATE"] = state
        env["CLAIM_AGENT"] = agent
        env["PATH"] = f"{self.bin}{os.pathsep}{env.get('PATH', '')}"
        return run_with_bash_path(
            ["bash", str(SCRIPT), *args],
            stub_directory=self.bin,
            cwd=self.cwd,
            env=env,
            capture_output=True,
            text=True,
        )

class HoldReviewTest(HoldTestCase):
    """Hermetic regressions for hold.sh: named per-holder review holds (#385)."""

    def test_add_creates_the_named_label_and_applies_it(self):
        result = self.run_hold("review", "add", "7", agent="sol")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("set hold:review:sol on PR #7", result.stdout)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("label create hold:review:sol", log)
        self.assertIn("pr edit 7 --repo example/canonical --add-label hold:review:sol", log)

    def test_add_does_not_recreate_an_existing_label(self):
        self.existing_labels.write_text('[{"name": "hold:review:sol"}]', encoding="utf-8")
        result = self.run_hold("review", "add", "7", agent="sol")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertNotIn("label create", log)

    def test_clear_removes_only_the_callers_own_named_label(self):
        result = self.run_hold("review", "clear", "7", agent="sol")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("cleared hold:review:sol on PR #7", result.stdout)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("pr edit 7 --repo example/canonical --remove-label hold:review:sol", log)
        self.assertNotIn("hold:review:luna", log)

    def test_different_agents_touch_different_labels(self):
        sol_result = self.run_hold("review", "add", "7", agent="sol")
        luna_result = self.run_hold("review", "add", "7", agent="luna")
        self.assertEqual(sol_result.returncode, 0, sol_result.stdout + sol_result.stderr)
        self.assertEqual(luna_result.returncode, 0, luna_result.stdout + luna_result.stderr)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("add-label hold:review:sol", log)
        self.assertIn("add-label hold:review:luna", log)

    def test_refuses_a_closed_pr(self):
        result = self.run_hold("review", "add", "7", agent="sol", state="MERGED")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("state is MERGED", result.stderr)

    def test_refuses_an_invalid_agent_id(self):
        result = self.run_hold("review", "add", "7", agent="Not Valid!")
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("lower-case stable agent id", result.stderr)

    def test_refuses_unknown_action(self):
        result = self.run_hold("review", "delete", "7", agent="sol")
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("usage:", result.stderr)

    def test_refuses_non_review_kind(self):
        result = self.run_hold("author", "add", "7", agent="sol")
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("usage:", result.stderr)


class HoldStewardTransferTest(HoldTestCase):
    """The steward path: clearing a *different* agent's hold, never silently."""

    def test_steward_clear_removes_the_named_holders_label_and_records_evidence(self):
        result = self.run_hold(
            "review", "clear", "7",
            "--steward", "luna", "--reason", "pushed fix at abc1234, luna's finding no longer applies",
            agent="opus-5",
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("steward-cleared hold:review:luna", result.stdout)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("pr edit 7 --repo example/canonical --remove-label hold:review:luna", log)
        self.assertIn("pr comment", log)
        comment = self.comment_body.read_text(encoding="utf-8")
        self.assertIn("**From:** opus-5", comment)
        self.assertIn("hold:review:luna", comment)
        self.assertIn("luna", comment)
        self.assertIn("pushed fix at abc1234", comment)

    def test_steward_clear_never_touches_a_different_holders_label(self):
        result = self.run_hold(
            "review", "clear", "7",
            "--steward", "luna", "--reason", "discharged",
            agent="opus-5",
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertNotIn("hold:review:opus-5", log)
        self.assertNotIn("hold:review:sol", log)

    def test_steward_flag_requires_a_reason(self):
        result = self.run_hold("review", "clear", "7", "--steward", "luna", agent="opus-5")
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("--steward and --reason must both be given", result.stderr)

    def test_reason_alone_without_steward_is_refused(self):
        result = self.run_hold("review", "clear", "7", "--reason", "discharged", agent="opus-5")
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("--steward and --reason must both be given", result.stderr)

    def test_steward_cannot_target_their_own_id(self):
        result = self.run_hold(
            "review", "clear", "7", "--steward", "opus-5", "--reason", "discharged", agent="opus-5",
        )
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("your own id", result.stderr)

    def test_steward_flags_are_refused_on_add(self):
        result = self.run_hold(
            "review", "add", "7", "--steward", "luna", "--reason", "discharged", agent="opus-5",
        )
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
        self.assertIn("only apply to clear", result.stderr)

    def test_plain_clear_by_the_steward_only_clears_their_own_label(self):
        # No --steward given: opus-5 clearing without it clears opus-5's own
        # label, never luna's, even though luna is mentioned nowhere here —
        # this is the regression for "the tool defaults to self, not to
        # whichever hold happens to be open."
        result = self.run_hold("review", "clear", "7", agent="opus-5")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("remove-label hold:review:opus-5", log)
        self.assertNotIn("pr comment", log)


if __name__ == "__main__":
    unittest.main()
