import os
import shutil
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("gate.sh")

CANONICAL_HTTPS = "https://github.com/example/canonical"
CANONICAL_SSH = "git@github.com:example/canonical"

GH_STUB = textwrap.dedent(
    """\
    #!/usr/bin/env bash
    # Stubbed gh for gate.sh mechanism tests. Values arrive via GATE_TEST_* env.
    case "$1 $2" in
      "repo view") printf '%s\\n' "$GATE_TEST_CANONICAL" ;;
      "pr view")
        printf '{"headRefOid":"%s","headRefName":"tb","isDraft":false,"state":"OPEN","mergeable":"MERGEABLE","labels":[]}\\n' "$GATE_TEST_HEAD"
        ;;
      "api repos/$GATE_TEST_CANONICAL/commits/"*check-runs*)
        printf '{"check_runs":[{"name":"ci","status":"completed","conclusion":"success","started_at":"1","completed_at":"2"}]}\\n'
        ;;
      "api repos/$GATE_TEST_CANONICAL/commits/"*)
        [ -n "$GATE_TEST_RESOLVE" ] && printf '%s\\n' "$GATE_TEST_RESOLVE"
        ;;
      "api repos/$GATE_TEST_CANONICAL/git/refs/heads/tb")
        printf '%s\\n' "$GATE_TEST_HEAD"
        ;;
      "api repos/$GATE_TEST_CANONICAL/pulls/1/files")
        printf '1\\n'
        ;;
      *) exit 0 ;;
    esac
    """
)

GIT_SHIM = textwrap.dedent(
    """\
    #!/usr/bin/env bash
    # Answers `remote get-url origin` with the URL under test so the preflight
    # sees exactly what production would resolve; everything else runs real
    # git against the local bare remote. gate.sh itself carries no test seam.
    if [ "$1 $2 $3" = "remote get-url origin" ]; then
      printf '%s\\n' "$GATE_TEST_ORIGIN_URL"
      exit 0
    fi
    exec "$GATE_TEST_REAL_GIT" "$@"
    """
)


class GateMechanismTest(unittest.TestCase):
    """Hermetic regressions for gate.sh: fork-origin/canonical split, reviewed-SHA
    resolution, and the full PASS/refuse verdicts. No network: the canonical URL
    is rewritten to a local bare repository via git insteadOf, and gh is a stub."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        self.bare = base / "canonical.git"
        subprocess.run(["git", "init", "-q", "--bare", str(self.bare)], check=True)

        seed = base / "seed"
        env = self.git_env()
        subprocess.run(["git", "init", "-q", "-b", "main", str(seed)], check=True, env=env)

        def commit(message: str) -> str:
            (seed / "f.txt").write_text(message)
            subprocess.run(["git", "add", "."], cwd=seed, check=True, env=env)
            subprocess.run(["git", "commit", "-q", "-m", message], cwd=seed, check=True, env=env)
            return subprocess.run(
                ["git", "rev-parse", "HEAD"], cwd=seed, check=True, env=env,
                capture_output=True, text=True,
            ).stdout.strip()

        self.main_sha = commit("base")
        self.head_sha = commit("pr head")
        subprocess.run(
            ["git", "push", "-q", str(self.bare),
             f"{self.main_sha}:refs/heads/main", f"{self.head_sha}:refs/pull/1/head",
             f"{self.head_sha}:refs/heads/tb"],
            cwd=seed, check=True, env=env,
        )

        self.gh = base / "gh"
        self.gh.write_text(GH_STUB, encoding="utf-8")
        self.gh.chmod(self.gh.stat().st_mode | stat.S_IXUSR)

    def tearDown(self):
        self.dir.cleanup()

    def git_env(self) -> dict[str, str]:
        env = os.environ.copy()
        env.update(
            GIT_TERMINAL_PROMPT="0",
            GIT_ASKPASS=os.devnull,
            GIT_CONFIG_NOSYSTEM="1",
            GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t",
            GIT_COMMITTER_NAME="t", GIT_COMMITTER_EMAIL="t@t",
        )
        return env

    def run_gate(
        self,
        *,
        origin: str,
        reviewed: str | None,
        resolve: str = "",
        head: str | None = None,
    ) -> subprocess.CompletedProcess[str]:
        base = Path(self.dir.name)
        checkout = base / "checkout"
        env = self.git_env()
        real_git = shutil.which("git")
        assert real_git is not None
        subprocess.run(["git", "init", "-q", str(checkout)], check=True, env=env)
        # Fetches go straight to the local bare repo — hermetic by construction.
        # The git shim (on PATH below) answers only `remote get-url origin` with
        # the URL under test, so the preflight exercises the same
        # resolved-transport check production runs, without any network.
        subprocess.run(
            ["git", "remote", "add", "origin", self.bare.as_uri()],
            cwd=checkout, check=True, env=env,
        )
        shim = base / "git"
        if not shim.exists():
            shim.write_text(GIT_SHIM, encoding="utf-8")
            shim.chmod(shim.stat().st_mode | stat.S_IXUSR)
        env["GATE_TEST_ORIGIN_URL"] = origin
        env["GATE_TEST_REAL_GIT"] = real_git

        env["PATH"] = f"{self.dir.name}{os.pathsep}{env['PATH']}"
        env["GATE_TEST_CANONICAL"] = "example/canonical"
        env["GATE_TEST_HEAD"] = head or self.head_sha
        env["GATE_TEST_RESOLVE"] = resolve
        env["GATE_MIN_CHECKS"] = "1"

        args = ["bash", str(SCRIPT), "1"]
        if reviewed is not None:
            args.append(reviewed)

        result = subprocess.run(
            args, cwd=checkout, env=env, text=True,
            capture_output=True, check=False, timeout=60,
        )
        subprocess.run(["rm", "-rf", str(checkout)], check=True)
        return result

    def test_rewritten_transport_refused_despite_canonical_label(self):
        # The insteadOf threat: the configured URL can look canonical while an
        # url.*.insteadOf rule redirects the actual fetch. Production reads the
        # *resolved* transport, so what matters is what get-url returns — here
        # the local bare path — and the gate must refuse it pre-verdict.
        result = self.run_gate(origin=self.bare.as_uri(), reviewed=self.head_sha)
        self.assertEqual(result.returncode, 2)
        self.assertIn("origin must be the", result.stderr)
        self.assertNotIn("gate:", result.stdout)

    def test_fork_origin_fails_before_any_verdict(self):
        result = self.run_gate(origin="https://github.com/example/fork", reviewed=self.head_sha)
        self.assertEqual(result.returncode, 2)
        self.assertIn("origin must be the", result.stderr)
        self.assertNotIn("gate:", result.stdout)

    def test_full_sha_on_canonical_origin_passes_completely(self):
        for origin in (CANONICAL_HTTPS, CANONICAL_SSH):
            result = self.run_gate(origin=origin, reviewed=self.head_sha)
            self.assertEqual(result.returncode, 0, (origin, result.stdout, result.stderr))
            self.assertIn("GATE: PASS", result.stdout)
            self.assertIn("PR head is the reviewed SHA", result.stdout)

    def test_short_abbreviation_refused(self):
        result = self.run_gate(origin=CANONICAL_HTTPS, reviewed=self.head_sha[:8])
        self.assertEqual(result.returncode, 2)
        self.assertIn("too short", result.stderr)
        self.assertNotIn("GATE:", result.stdout)

    def test_unresolved_abbreviation_refused(self):
        result = self.run_gate(origin=CANONICAL_HTTPS, reviewed=self.head_sha[:12], resolve="")
        self.assertEqual(result.returncode, 2)
        self.assertIn("does not resolve", result.stderr)
        self.assertNotIn("GATE:", result.stdout)

    def test_resolved_abbreviation_passes_completely(self):
        result = self.run_gate(
            origin=CANONICAL_HTTPS, reviewed=self.head_sha[:12], resolve=self.head_sha
        )
        self.assertEqual(result.returncode, 0, (result.stdout, result.stderr))
        self.assertIn(f"resolved abbreviated {self.head_sha[:12]} to {self.head_sha}", result.stdout)
        self.assertIn("GATE: PASS", result.stdout)

    def test_moved_head_fails_the_gate(self):
        # Reviewed the older commit; the PR head has moved on.
        result = self.run_gate(
            origin=CANONICAL_HTTPS, reviewed=self.main_sha[:12], resolve=self.main_sha
        )
        self.assertEqual(result.returncode, 1)
        self.assertIn("re-review the new head", result.stdout)
        self.assertIn("GATE: FAIL", result.stdout)


if __name__ == "__main__":
    unittest.main()
