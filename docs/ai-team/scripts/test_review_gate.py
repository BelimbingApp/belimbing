import json
import os
import tempfile
import unittest
from pathlib import Path

from _test_support import bash_path, run_with_bash_path


SCRIPT = Path(__file__).with_name("review_gate.sh")
SHA = "a" * 40
STALE_SHA = "b" * 40


class ReviewGateTest(unittest.TestCase):
    def run_gate(
        self,
        reviews,
        labels=("agent:author",),
        reviewed=SHA,
        head_sha=SHA,
        identity=None,
    ):
        if identity is None:
            identity = {
                "user": {"id": 1, "login": "human-author", "type": "User"},
                "head": {"repo": {"id": 100}},
                "base": {"repo": {"id": 100}},
            }
        with tempfile.TemporaryDirectory() as directory:
            fixture = Path(directory) / "review.json"
            fixture.write_text(
                json.dumps({
                    "reviewed": reviewed,
                    "head_sha": head_sha,
                    "labels": list(labels),
                    "identity": identity,
                    "reviews": reviews,
                }),
                encoding="utf-8",
            )
            env = os.environ.copy()
            env["REVIEW_GATE_INPUT"] = str(fixture)
            return run_with_bash_path(
                ["bash", bash_path(SCRIPT)],
                stub_directory=Path(directory),
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

    def review(self, agent="reviewer", state="COMMENTED", body=None, commit_id=SHA, at="2026-01-01T00:00:00Z"):
        if body is None:
            body = f"**From:** {agent}\n\n**Verdict:** accept"
        return {
            "id": 1,
            "state": state,
            "body": body,
            "commit_id": commit_id,
            "submitted_at": at,
        }

    def test_commented_exact_head_acceptance_passes(self):
        result = self.run_gate([self.review()])

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("independent exact-head acceptance from reviewer", result.stdout)

    def test_native_approval_still_requires_a_from_marker(self):
        result = self.run_gate([
            self.review(state="APPROVED", body="**From:** reviewer"),
        ])

        self.assertEqual(result.returncode, 0, result.stderr)

        missing_marker = self.run_gate([
            self.review(state="APPROVED", body="approved"),
        ])
        self.assertEqual(missing_marker.returncode, 1)
        self.assertIn("no independent exact-head acceptance", missing_marker.stdout)

    def test_stale_review_is_not_an_acceptance(self):
        result = self.run_gate([self.review(commit_id=STALE_SHA)])

        self.assertEqual(result.returncode, 1)
        self.assertIn("no independent exact-head acceptance", result.stdout)

    def test_moved_head_refuses_a_stale_review_event(self):
        result = self.run_gate([self.review()], head_sha=STALE_SHA)

        self.assertEqual(result.returncode, 1)
        self.assertIn("reviewed SHA is not the current PR head", result.stdout)

    def test_author_cannot_accept_their_own_lane(self):
        result = self.run_gate([self.review(agent="author")])

        self.assertEqual(result.returncode, 1)
        self.assertIn("no independent exact-head acceptance", result.stdout)

    def test_latest_changes_required_supersedes_acceptance(self):
        result = self.run_gate([
            self.review(at="2026-01-01T00:00:00Z"),
            self.review(
                state="COMMENTED",
                body="**From:** reviewer\n\n**Verdict:** changes required",
                at="2026-01-01T00:01:00Z",
            ),
        ])

        self.assertEqual(result.returncode, 1)
        self.assertIn("changes required by reviewer", result.stdout)

    def test_comment_style_verdict_is_rejected(self):
        result = self.run_gate([
            self.review(body="**From:** reviewer\n\nVerdict: accept"),
        ])

        self.assertEqual(result.returncode, 1)
        self.assertIn("rejected for format", result.stdout)

    def test_exact_dependabot_identity_is_an_implicit_author_lane(self):
        result = self.run_gate(
            [self.review()],
            labels=(),
            identity=self.dependabot_identity(),
        )

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("independent exact-head acceptance from reviewer", result.stdout)

    def test_dependabot_lookalikes_do_not_get_the_implicit_lane(self):
        for identity in (
            self.dependabot_identity(user_id=1),
            self.dependabot_identity(login="contributor"),
            self.dependabot_identity(user_type="User"),
            self.dependabot_identity(head_repo_id=200),
            self.dependabot_identity(head_repo_id=None),
        ):
            with self.subTest(identity=identity):
                result = self.run_gate([self.review()], labels=(), identity=identity)

                self.assertEqual(result.returncode, 1)
                self.assertIn("expected exactly one agent:<id> author lane", result.stdout)

    def test_dependabot_cannot_carry_a_human_author_lane(self):
        result = self.run_gate(
            [self.review()],
            labels=("agent:spoofed",),
            identity=self.dependabot_identity(),
        )

        self.assertEqual(result.returncode, 1)
        self.assertIn("must not carry agent:<id> labels", result.stdout)

    def test_changes_required_still_blocks_dependabot(self):
        result = self.run_gate(
            [self.review(
                state="COMMENTED",
                body="**From:** reviewer\n\n**Verdict:** changes required",
            )],
            labels=(),
            identity=self.dependabot_identity(),
        )

        self.assertEqual(result.returncode, 1)
        self.assertIn("changes required by reviewer", result.stdout)

    @staticmethod
    def dependabot_identity(
        *,
        user_id=49699333,
        login="dependabot[bot]",
        user_type="Bot",
        head_repo_id=100,
        base_repo_id=100,
    ):
        return {
            "user": {"id": user_id, "login": login, "type": user_type},
            "head": {"repo": {"id": head_repo_id}},
            "base": {"repo": {"id": base_repo_id}},
        }


if __name__ == "__main__":
    unittest.main()
