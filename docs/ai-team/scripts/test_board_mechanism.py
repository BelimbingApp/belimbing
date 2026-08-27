import json
import os
import re
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

from _test_support import run_with_bash_path


SCRIPT = Path(__file__).with_name("board.sh")

# gate.sh's own From-marker contract (gate.sh:~203). post output must satisfy
# it, or board.sh would mint posts the gate cannot attribute.
GATE_FROM_REGEX = re.compile(
    r"^\*\*From:\*\*\s*(?P<agent>[a-z0-9]+(?:[._-][a-z0-9]+)*)(?:\s|$)", re.IGNORECASE
)


def gh_capture_stub(directory: Path, comments_json: str = "[]") -> Path:
    """A gh stub that records `issue comment` bodies and serves fixture JSON."""
    gh = directory / "gh"
    gh.write_text(
        textwrap.dedent(
            """\
            #!/usr/bin/env bash
            if [ "$1" = "issue" ] && [ "$2" = "comment" ]; then
              cat > "$BOARD_TEST_CAPTURE"
              exit 0
            fi
            if [ "$1" = "issue" ] && [ "$2" = "view" ]; then
              cat "$BOARD_TEST_FIXTURE"
              exit 0
            fi
            if { [ "$1" = "pr" ] || [ "$1" = "issue" ]; } && [ "$2" = "list" ]; then
              printf '%s' "${BOARD_TEST_LIST:-}"
              exit 0
            fi
            exit 1
            """
        ),
        encoding="utf-8",
    )
    gh.chmod(gh.stat().st_mode | stat.S_IXUSR)
    return gh


class BoardMechanismTest(unittest.TestCase):
    def run_board(self, args, directory: Path, env_extra=None, stdin: str = ""):
        env = os.environ.copy()
        env["BOARD_TEST_CAPTURE"] = str(directory / "captured-body")
        env["BOARD_TEST_FIXTURE"] = str(directory / "fixture.json")
        env.update(env_extra or {})
        return run_with_bash_path(
            ["bash", str(SCRIPT), *args],
            stub_directory=directory,
            env=env,
            text=True,
            input=stdin,
            capture_output=True,
            check=False,
        )

    # ---- post ----

    def test_post_stamps_the_header_gate_parses(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            result = self.run_board(
                ["post", "42", "--agent", "fable", "--type", "status", "head is abc1234"],
                directory,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            body = (directory / "captured-body").read_text(encoding="utf-8")
            first_line = body.splitlines()[0]
            match = GATE_FROM_REGEX.match(first_line)
            self.assertIsNotNone(match, f"gate cannot parse: {first_line!r}")
            self.assertEqual(match.group("agent"), "fable")
            self.assertIn("**Type:** status", body)
            self.assertIn("head is abc1234", body)

    def test_post_folds_overflow_into_details_at_a_line_boundary(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            body_in = "\n".join(f"line {i:03d} " + "x" * 40 for i in range(60))
            result = self.run_board(
                ["post", "42", "--agent", "fable", "--type", "finding"],
                directory,
                env_extra={"BOARD_POST_BUDGET": "400"},
                stdin=body_in,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            body = (directory / "captured-body").read_text(encoding="utf-8")
            visible = body.split("<details>")[0]
            self.assertLessEqual(
                len(visible.encode()), 400 + 120, "visible part exceeds budget plus header"
            )
            self.assertIn("<details>", body)
            self.assertIn("line 059", body, "folded remainder must survive inside the details")
            # Nothing is lost at the fold boundary: every input line is present.
            for i in range(60):
                self.assertIn(f"line {i:03d}", body)

    def test_post_refuses_verdicts_and_points_at_pr_review(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            result = self.run_board(
                ["post", "42", "--agent", "fable", "--type", "verdict", "accept"],
                directory,
            )
            self.assertEqual(result.returncode, 3)
            self.assertIn("invisible to gate.sh", result.stderr)
            self.assertIn("gh pr review", result.stderr)
            self.assertFalse((directory / "captured-body").exists(), "nothing may be posted")

    def test_post_requires_an_agent_identity(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            env = {k: v for k, v in os.environ.items()}
            env.pop("CLAIM_AGENT", None)
            env.pop("BOARD_AGENT", None)
            result = run_with_bash_path(
                ["bash", str(SCRIPT), "post", "42", "--type", "status", "hello"],
                stub_directory=directory,
                env={**env, "BOARD_TEST_CAPTURE": str(directory / "captured-body"),
                     "BOARD_TEST_FIXTURE": str(directory / "fixture.json")},
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(result.returncode, 2)
            self.assertIn("agent id required", result.stderr)

    # ---- digest ----

    def digest_fixture(self) -> str:
        structured = (
            "**From:** sol\n\n**Type:** finding\n\n"
            + "the lease is load-bearing\n"
            + "<details>\n<summary>evidence</summary>\n\nreams of git output\n\n</details>\n"
            + "\n".join(f"detail line {i}" for i in range(20))
        )
        return json.dumps(
            {
                "number": 42,
                "title": "Example lane",
                "state": "OPEN",
                "labels": [{"name": "task:review"}, {"name": "agent:fable"}],
                "comments": [
                    {"body": structured, "createdAt": "2026-08-27T04:00:00Z",
                     "author": {"login": "kiatng"}},
                    {"body": "Owner response: fork is not involved", "createdAt": "2026-08-27T04:01:00Z",
                     "author": {"login": "kiatng"}},
                    {"body": "## Quality Gate Passed\nbot noise", "createdAt": "2026-08-27T04:02:00Z",
                     "author": {"login": "sonarqubecloud"}},
                ],
            }
        )

    def test_digest_renders_structured_posts_and_counts_noise(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            (directory / "fixture.json").write_text(self.digest_fixture(), encoding="utf-8")
            result = self.run_board(["digest", "42"], directory)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("== #42 [OPEN] Example lane", result.stdout)
            self.assertIn("task:review", result.stdout)
            self.assertIn("-- sol", result.stdout)
            self.assertIn("the lease is load-bearing", result.stdout)
            # P1 (#364): an unheadered post from a human account may be the
            # owner, whose rulings outrank every marker — rendered, never hidden.
            self.assertIn("[no header] kiatng", result.stdout)
            self.assertIn("Owner response: fork is not involved", result.stdout)
            # Bot posts are ignored with a count, not rendered and not nagged.
            self.assertIn("1 bot post(s) ignored", result.stdout)
            self.assertNotIn("Quality Gate Passed", result.stdout)

    def test_digest_strips_folded_details_and_truncates_long_posts(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            (directory / "fixture.json").write_text(self.digest_fixture(), encoding="utf-8")
            result = self.run_board(
                ["digest", "42"], directory, env_extra={"BOARD_DIGEST_LINES": "5"}
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("[folded detail omitted]", result.stdout)
            self.assertNotIn("reams of git output", result.stdout)
            self.assertIn("more lines — read the thread only if you need them", result.stdout)
            self.assertNotIn("detail line 19", result.stdout)

    def test_post_budget_cut_inside_a_multibyte_character_stays_valid_utf8(self):
        # P3 (#364): a single long line with no newline inside the budget used
        # to be cut mid-character by head -c. The visible part must decode as
        # UTF-8 and every input character must survive somewhere in the post.
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            body_in = "\u00e9" * 300  # 600 bytes of two-byte characters, no newline
            result = self.run_board(
                ["post", "42", "--agent", "fable", "--type", "finding"],
                directory,
                env_extra={"BOARD_POST_BUDGET": "201"},
                stdin=body_in,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            raw_bytes = (directory / "captured-body").read_bytes()
            decoded = raw_bytes.decode("utf-8")  # raises on a split character
            self.assertEqual(decoded.count("\u00e9"), 300, "no character may be lost")

    # ---- hygiene ----

    def test_hygiene_counts_unstructured_posts_on_active_lanes(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            (directory / "fixture.json").write_text(self.digest_fixture(), encoding="utf-8")
            result = self.run_board(
                ["hygiene"], directory, env_extra={"BOARD_TEST_LIST": "42\n"}
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            # P2 (#364): the bot comment is excluded — only the human-account
            # unheadered post counts, since only that one could have been an
            # agent posting correctly.
            self.assertIn("#42 has 1 unstructured post(s)", result.stdout)

    def test_hygiene_reports_clean_when_every_post_is_structured(self):
        with tempfile.TemporaryDirectory() as raw:
            directory = Path(raw)
            gh_capture_stub(directory)
            clean = json.dumps(
                {
                    "number": 7,
                    "title": "Clean lane",
                    "state": "OPEN",
                    "labels": [],
                    "comments": [
                        {"body": "**From:** fable\n\n**Type:** status\n\nok", "createdAt": "x",
                         "author": {"login": "kiatng"}},
                        {"body": "## Quality Gate Passed", "createdAt": "x",
                         "author": {"login": "sonarqubecloud"}},
                    ],
                }
            )
            (directory / "fixture.json").write_text(clean, encoding="utf-8")
            result = self.run_board(
                ["hygiene"], directory, env_extra={"BOARD_TEST_LIST": "7\n"}
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("ok      every post on active lanes carries the machine header", result.stdout)


if __name__ == "__main__":
    unittest.main()
