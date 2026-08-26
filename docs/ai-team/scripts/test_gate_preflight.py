import os
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("gate.sh")

HEAD = "a" * 40
OTHER = "b" * 40

GH_STUB = textwrap.dedent(
    """\
    #!/usr/bin/env bash
    # Stubbed gh for gate.sh preflight tests. Values via GATE_TEST_* env vars.
    case "$1 $2" in
      "repo view") printf '%s\\n' "$GATE_TEST_CANONICAL" ;;
      "pr view")
        printf '{"headRefOid":"%s","headRefName":"tb","isDraft":false,"state":"OPEN","mergeable":"MERGEABLE","labels":[]}\\n' "$GATE_TEST_HEAD"
        ;;
      "api repos/$GATE_TEST_CANONICAL/commits/"*)
        [ -n "$GATE_TEST_RESOLVE" ] && printf '%s\\n' "$GATE_TEST_RESOLVE"
        ;;
      *) exit 0 ;;
    esac
    """
)


class GatePreflightTest(unittest.TestCase):
    """Regressions for the fork-origin/canonical split and reviewed-SHA resolution."""

    def run_gate(
        self,
        *,
        origin: str,
        reviewed: str | None,
        resolve: str = "",
        head: str = HEAD,
    ) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory() as directory:
            repo = Path(directory) / "checkout"
            repo.mkdir()
            subprocess.run(["git", "init", "-q"], cwd=repo, check=True)
            subprocess.run(["git", "remote", "add", "origin", origin], cwd=repo, check=True)

            gh = Path(directory) / "gh"
            gh.write_text(GH_STUB, encoding="utf-8")
            gh.chmod(gh.stat().st_mode | stat.S_IXUSR)

            env = os.environ.copy()
            env["PATH"] = f"{directory}{os.pathsep}{env['PATH']}"
            env["GATE_TEST_CANONICAL"] = "example/canonical"
            env["GATE_TEST_HEAD"] = head
            env["GATE_TEST_RESOLVE"] = resolve

            args = ["bash", str(SCRIPT), "1"]
            if reviewed is not None:
                args.append(reviewed)

            return subprocess.run(
                args, cwd=repo, env=env, text=True, capture_output=True, check=False
            )

    def test_fork_origin_fails_before_any_verdict(self):
        result = self.run_gate(
            origin="https://github.com/example/fork.git", reviewed=HEAD
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn("origin must be the", result.stderr + result.stdout)
        self.assertNotIn("gate:", result.stdout)

    def test_canonical_origin_https_and_ssh_pass_preflight(self):
        for origin in (
            "https://github.com/example/canonical.git",
            "git@github.com:example/canonical.git",
        ):
            result = self.run_gate(origin=origin, reviewed=HEAD)
            self.assertIn("gate: example/canonical #1", result.stdout, origin)
            self.assertIn("PR head is the reviewed SHA", result.stdout, origin)

    def test_short_abbreviation_refused(self):
        result = self.run_gate(
            origin="https://github.com/example/canonical.git", reviewed=HEAD[:8]
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn("too short", result.stderr)

    def test_unresolved_abbreviation_refused(self):
        result = self.run_gate(
            origin="https://github.com/example/canonical.git",
            reviewed=HEAD[:12],
            resolve="",
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn("does not resolve", result.stderr)

    def test_resolved_abbreviation_matches_head(self):
        result = self.run_gate(
            origin="https://github.com/example/canonical.git",
            reviewed=HEAD[:12],
            resolve=HEAD,
        )
        self.assertIn(f"resolved abbreviated {HEAD[:12]} to {HEAD}", result.stdout)
        self.assertIn("PR head is the reviewed SHA", result.stdout)

    def test_moved_head_refused_even_when_abbreviation_resolves(self):
        result = self.run_gate(
            origin="https://github.com/example/canonical.git",
            reviewed=OTHER[:12],
            resolve=OTHER,
        )
        self.assertEqual(result.returncode, 1)
        self.assertIn("re-review the new head", result.stdout)


if __name__ == "__main__":
    unittest.main()
