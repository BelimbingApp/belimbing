import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

from _test_support import bash_path

HELPER = Path(__file__).with_name("_default_branch.sh")


class DefaultBranchResolutionTest(unittest.TestCase):
    """The package must not assume `main`.

    Every fixture in this suite hardcoded `main` before #445, which is exactly
    why 24 hardcoded references survived across five scripts. These tests are
    written against a `master`-default repository on purpose: if the resolver
    regresses to a constant, they fail.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.base = Path(self.dir.name)
        self.remote = self.base / "remote.git"
        self.work = self.base / "work"
        subprocess.run(["git", "init", "--bare", "-q", str(self.remote)], check=True)
        subprocess.run(
            ["git", "--git-dir", str(self.remote), "symbolic-ref", "HEAD", "refs/heads/master"],
            check=True,
        )
        subprocess.run(["git", "init", "-q", "-b", "master", str(self.work)], check=True)
        self._git("config", "user.email", "test@example.invalid")
        self._git("config", "user.name", "Test Agent")
        (self.work / "a.txt").write_text("a\n", encoding="utf-8")
        self._git("add", "a.txt")
        self._git("commit", "-q", "-m", "base")
        self._git("remote", "add", "origin", str(self.remote))
        self._git("push", "-q", "-u", "origin", "master")

    def tearDown(self):
        self.dir.cleanup()

    def _git(self, *args):
        subprocess.run(["git", *args], cwd=self.work, check=True, capture_output=True)

    def resolve(self, env_extra=None, cwd=None):
        env = os.environ.copy()
        env.pop("AI_TEAM_BASE_BRANCH", None)
        env.update(env_extra or {})
        script = f'source {bash_path(HELPER)}\nai_team_default_branch\n'
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=str(cwd or self.work),
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip()

    def test_a_master_default_repository_resolves_master_not_main(self):
        self.assertEqual(self.resolve(), "master")

    def test_an_explicit_override_wins_over_observed_state(self):
        self.assertEqual(self.resolve({"AI_TEAM_BASE_BRANCH": "release"}), "release")

    def test_a_stale_origin_head_is_rejected_rather_than_trusted(self):
        # origin/HEAD naming a branch that no longer exists on origin is a real
        # observed condition, not a hypothetical: it is what one adopting
        # checkout carried while its actual default had moved on.
        subprocess.run(
            ["git", "symbolic-ref", "refs/remotes/origin/HEAD", "refs/remotes/origin/deleted-branch"],
            cwd=self.work, check=True, capture_output=True,
        )
        self.assertEqual(self.resolve(), "master")

    def test_it_falls_back_to_main_when_nothing_is_observable(self):
        empty = self.base / "empty"
        subprocess.run(["git", "init", "-q", str(empty)], check=True)
        self.assertEqual(self.resolve(cwd=empty), "main")

    def test_no_shipped_script_hardcodes_the_default_branch(self):
        # The regression this whole change exists to prevent. A new script that
        # writes origin/main directly fails here rather than at an adopter's
        # first orient.
        offenders = []
        for script in sorted(Path(__file__).parent.glob("*.sh")):
            text = script.read_text(encoding="utf-8")
            for number, line in enumerate(text.splitlines(), start=1):
                if line.lstrip().startswith("#"):
                    continue
                if "origin/main" in line or "origin main" in line:
                    offenders.append(f"{script.name}:{number}: {line.strip()}")
        self.assertEqual(offenders, [], "hardcoded default branch:\n" + "\n".join(offenders))

    def test_no_shipped_script_hardcodes_a_particular_repository(self):
        offenders = []
        for script in sorted(Path(__file__).parent.glob("*.sh")):
            for number, line in enumerate(script.read_text(encoding="utf-8").splitlines(), start=1):
                if line.lstrip().startswith("#"):
                    continue
                if "BelimbingApp/belimbing" in line:
                    offenders.append(f"{script.name}:{number}: {line.strip()}")
        self.assertEqual(offenders, [], "hardcoded repository:\n" + "\n".join(offenders))


if __name__ == "__main__":
    unittest.main()
