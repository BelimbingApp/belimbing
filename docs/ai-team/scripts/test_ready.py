import os
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

from _test_support import bash_path, run_with_bash_path

SCRIPT = Path(__file__).with_name("ready.sh")


class ReadyHandoffTest(unittest.TestCase):
    """Hermetic regressions for ready.sh: Closes keyword re-assert + label handoff."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        self.body_path = base / "pr-body.txt"
        self.body_path.write_text(
            "**From:** composer\n\nImplementation notes only — keyword stripped.\n",
            encoding="utf-8",
        )
        self.bin = base / "bin"
        self.bin.mkdir()
        self.gh_log = base / "gh.log"
        gh = self.bin / "gh"
        gh.write_text(
            textwrap.dedent(
                f"""\
                #!/usr/bin/env bash
                set -euo pipefail
                log="$READY_TEST_GH_LOG"
                body_path="$READY_TEST_BODY"
                printf '%s\\n' "$*" >>"$log"
                case "$1 $2" in
                  "repo view")
                    printf 'example/canonical\\n'
                    ;;
                  "pr view")
                    body=$(cat "$body_path")
                    jq -n --arg body "$body" '{{
                      number: 99,
                      title: "claim body closes (#42)",
                      body: $body,
                      headRefName: "agent/composer-issue-42",
                      labels: [{{"name":"agent:composer"}},{{"name":"task:active"}}],
                      isDraft: true,
                      state: "OPEN"
                    }}'
                    ;;
                  "pr edit")
                    prev=""
                    for arg in "$@"; do
                      if [ "$prev" = "--body-file" ]; then
                        cp "$arg" "$body_path"
                      fi
                      prev="$arg"
                    done
                    ;;
                  "pr ready"|"issue edit")
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

    def run_ready(self) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env["READY_TEST_GH_LOG"] = bash_path(self.gh_log)
        env["READY_TEST_BODY"] = bash_path(self.body_path)
        env["CLAIM_AGENT"] = "composer"
        env["PATH"] = f"{self.bin}{os.pathsep}{env.get('PATH', '')}"
        return run_with_bash_path(
            ["bash", str(SCRIPT), "99"],
            stub_directory=self.bin,
            cwd=self.cwd,
            env=env,
            capture_output=True,
            text=True,
        )

    def test_ready_reasserts_closes_when_body_lost_it(self):
        result = self.run_ready()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("re-asserted Closes #42", result.stdout)
        self.assertIn("PR #99 ready for review (Closes #42)", result.stdout)
        body = self.body_path.read_text(encoding="utf-8")
        self.assertRegex(body, r"(?m)^Closes #42\s*$")
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertIn("pr ready", log)
        self.assertIn("task:review", log)

    def test_ready_skips_rewrite_when_closes_already_present(self):
        self.body_path.write_text(
            "**From:** composer\n\nClaiming #42 through claim.sh.\n\nCloses #42\n",
            encoding="utf-8",
        )
        result = self.run_ready()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertNotIn("re-asserted", result.stdout)
        log = self.gh_log.read_text(encoding="utf-8")
        self.assertNotRegex(log, r"pr edit .*--body-file")


if __name__ == "__main__":
    unittest.main()
