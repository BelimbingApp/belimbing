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

SCRIPT = Path(__file__).with_name("decide.sh")

FROM_REGEX = re.compile(
    r"^\*\*From:\*\*\s*(?P<agent>[a-z0-9]+(?:[._-][a-z0-9]+)*)(?:\s|$)", re.IGNORECASE
)


class DecideTestCase(unittest.TestCase):
    """Shared gh-stub harness for decide.sh.

    Comment state is a JSON array file (DECIDE_TEST_COMMENTS) that
    `gh issue comment` appends to (assigning each a strictly increasing
    synthetic createdAt so ordering/latest-vote-wins behaves like real
    GitHub) and `gh issue view --json comments` serves back wrapped as
    {"comments": [...]}. The active-agent roster (DECIDE_TEST_ROSTER) is a
    JSON array of agent ids the stub turns into one fake open issue per
    agent, matching decide.sh's active_agents() query shape.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        base = Path(self.dir.name)
        self.base = base
        self.bin = base / "bin"
        self.bin.mkdir()
        self.comments = base / "comments.json"
        self.comments.write_text("[]", encoding="utf-8")
        self.counter = base / "counter.txt"
        self.roster = base / "roster.json"
        self.roster.write_text("[]", encoding="utf-8")
        self.log = base / "gh.log"
        self.write_gh_stub()

    def tearDown(self):
        self.dir.cleanup()

    def write_gh_stub(self):
        gh = self.bin / "gh"
        gh.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\\n' "$*" >>"$DECIDE_TEST_LOG" 2>/dev/null || true

                case "$1 $2" in
                  "repo view")
                    printf 'example/canonical\\n'
                    ;;
                  "issue view")
                    jq -n --slurpfile c "$DECIDE_TEST_COMMENTS" '{comments: $c[0]}'
                    ;;
                  "issue comment")
                    body=$(cat)
                    n=$(( $(cat "$DECIDE_TEST_COUNTER" 2>/dev/null || echo 0) + 1 ))
                    printf '%s' "$n" >"$DECIDE_TEST_COUNTER"
                    ts=$(printf '2026-01-01T%02d:%02d:%02dZ' $((n/3600)) $(((n/60)%60)) $((n%60)))
                    jq --arg body "$body" --arg ts "$ts" \\
                      '. + [{body: $body, createdAt: $ts, author: {login: "shared-account"}}]' \\
                      "$DECIDE_TEST_COMMENTS" >"$DECIDE_TEST_COMMENTS.tmp"
                    mv "$DECIDE_TEST_COMMENTS.tmp" "$DECIDE_TEST_COMMENTS"
                    ;;
                  "pr list")
                    printf ''
                    ;;
                  "issue list")
                    jq -r '.[]' "$DECIDE_TEST_ROSTER"
                    ;;
                  *)
                    echo "unexpected gh invocation: $*" >&2
                    exit 1
                    ;;
                esac
                """
            ),
            encoding="utf-8",
        )
        gh.chmod(gh.stat().st_mode | stat.S_IXUSR)

    # ---- helpers ----

    def env(self, extra=None):
        e = os.environ.copy()
        e["DECIDE_TEST_COMMENTS"] = str(self.comments)
        e["DECIDE_TEST_COUNTER"] = str(self.counter)
        e["DECIDE_TEST_ROSTER"] = str(self.roster)
        e["DECIDE_TEST_LOG"] = str(self.log)
        e["DECIDE_REPO"] = "example/canonical"
        e.update(extra or {})
        return e

    def set_roster(self, agents: list[str]) -> None:
        self.roster.write_text(json.dumps(agents), encoding="utf-8")

    def run_decide(self, *args, agent: str = "sol", extra_env=None):
        env = self.env(extra_env)
        env["CLAIM_AGENT"] = agent
        return run_with_bash_path(
            ["bash", str(SCRIPT), *args],
            stub_directory=self.bin,
            cwd=self.base,
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )

    def comments_now(self) -> list[dict]:
        return json.loads(self.comments.read_text(encoding="utf-8"))

    def seed_comment(self, body: str) -> None:
        """Append a comment directly (bypassing the CLI) with a synthetic
        strictly-increasing createdAt, for constructing scenarios (already-past
        deadlines, malformed posts) the CLI itself would refuse to produce."""
        comments = self.comments_now()
        n = len(comments) + 1
        ts = f"2025-01-01T{n // 3600:02d}:{(n // 60) % 60:02d}:{n % 60:02d}Z"
        comments.append({"body": body, "createdAt": ts, "author": {"login": "shared-account"}})
        self.comments.write_text(json.dumps(comments), encoding="utf-8")

    def propose(self, issue, id_, options, recommend, agent="proposer", deadline_minutes=30, question="Which way?"):
        result = self.run_decide(
            "propose", str(issue), "--id", id_, "--question", question,
            "--options", options, "--recommend", recommend,
            "--deadline-minutes", str(deadline_minutes),
            agent=agent,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return result

    def vote(self, issue, id_, option, agent, rationale="because reasons"):
        return self.run_decide("vote", str(issue), "--id", id_, "--option", option, rationale, agent=agent)

    def close(self, issue, id_, agent="closer", decision=None, rationale=None, owner=None, authority_effect=None):
        args = ["close", str(issue), "--id", id_]
        if decision is not None:
            args += ["--decision", decision]
        if rationale is not None:
            args += ["--rationale", rationale]
        if owner is not None:
            args += ["--owner", owner]
        if authority_effect is not None:
            args += ["--authority-effect", authority_effect]
        return self.run_decide(*args, agent=agent)

    def last_decision_body(self) -> str:
        for c in reversed(self.comments_now()):
            if "**Type:** decision" in c["body"]:
                return c["body"]
        self.fail("no decision comment was posted")


class ProposeValidationTest(DecideTestCase):
    def test_propose_stamps_a_gate_readable_from_header(self):
        self.set_roster(["proposer"])
        self.propose(10, "locale-order", "keep,swap", "keep", agent="proposer")
        body = self.comments_now()[0]["body"]
        self.assertIsNotNone(FROM_REGEX.match(body))
        self.assertIn("**Decision:** locale-order", body)
        self.assertIn("**Options:** keep,swap", body)
        self.assertIn("**Recommend:** keep", body)

    def test_propose_rejects_duplicate_decision_id(self):
        self.set_roster(["proposer"])
        self.propose(10, "locale-order", "keep,swap", "keep")
        second = self.run_decide(
            "propose", "10", "--id", "locale-order", "--question", "again?",
            "--options", "a,b", "--recommend", "a", agent="proposer",
        )
        self.assertNotEqual(second.returncode, 0)
        self.assertIn("already has a proposal", second.stderr)

    def test_propose_rejects_recommend_not_in_options(self):
        result = self.run_decide(
            "propose", "10", "--id", "x", "--question", "q?",
            "--options", "a,b", "--recommend", "c", agent="proposer",
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not one of the declared", result.stderr)

    def test_propose_rejects_deadline_over_one_heartbeat(self):
        result = self.run_decide(
            "propose", "10", "--id", "x", "--question", "q?",
            "--options", "a,b", "--recommend", "a", "--deadline-minutes", "31",
            agent="proposer",
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("one heartbeat", result.stderr)

    def test_propose_rejects_a_single_option(self):
        result = self.run_decide(
            "propose", "10", "--id", "x", "--question", "q?",
            "--options", "a", "--recommend", "a", agent="proposer",
        )
        self.assertNotEqual(result.returncode, 0)

    def test_propose_rejects_duplicate_options(self):
        result = self.run_decide(
            "propose", "10", "--id", "x", "--question", "q?",
            "--options", "a,a", "--recommend", "a", agent="proposer",
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("duplicate option", result.stderr)


class VoteValidationTest(DecideTestCase):
    def test_vote_refuses_a_decision_id_with_no_open_proposal(self):
        self.set_roster(["proposer", "voter"])
        result = self.vote(10, "no-such-decision", "keep", agent="voter")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("no open proposal", result.stderr)
        self.assertEqual(self.comments_now(), [])

    def test_vote_refuses_multiple_options_in_one_flag(self):
        self.set_roster(["proposer"])
        self.propose(10, "locale-order", "keep,swap", "keep")
        before = len(self.comments_now())
        result = self.vote(10, "locale-order", "keep,swap", agent="voter")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("exactly one option", result.stderr)
        self.assertEqual(len(self.comments_now()), before)

    def test_vote_refuses_an_undeclared_option(self):
        self.set_roster(["proposer"])
        self.propose(10, "locale-order", "keep,swap", "keep")
        result = self.vote(10, "locale-order", "rewrite-everything", agent="voter")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not one of this proposal's declared options", result.stderr)

    def test_vote_refuses_after_close(self):
        self.set_roster(["a"])
        self.propose(10, "d", "x,y", "x", agent="a")
        self.vote(10, "d", "x", agent="a")
        closed = self.close(10, "d", agent="a")
        self.assertEqual(closed.returncode, 0, closed.stderr)
        late = self.vote(10, "d", "y", agent="a")
        self.assertNotEqual(late.returncode, 0)
        self.assertIn("already closed", late.stderr)


class TallyAndCloseTest(DecideTestCase):
    def test_majority_selects_the_clear_winner_without_a_tie_break(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "left", agent="b")
        self.vote(10, "d", "right", agent="c")

        result = self.close(10, "d", agent="p")
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.last_decision_body()
        self.assertIn("**Chosen:** left", body)
        self.assertIn("left=2", body)
        self.assertIn("right=1", body)
        self.assertNotIn("**Tie-Break:**", body)

    def test_latest_vote_per_agent_replaces_the_earlier_one(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "right", agent="a")  # a changes their mind
        self.vote(10, "d", "right", agent="b")
        self.vote(10, "d", "right", agent="c")

        result = self.close(10, "d", agent="p")
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.last_decision_body()
        self.assertIn("**Chosen:** right", body)
        self.assertIn("right=3", body)
        self.assertNotIn("left=", body)  # a's stale "left" vote must not linger

    def test_a_vote_with_conflicting_from_identities_is_excluded(self):
        # roster has 5 active agents so quorum needs 3 *distinct* valid votes;
        # a's malformed post must not be one of them, so b/c/e supply the
        # three that make quorum while a's noise is proven excluded.
        self.set_roster(["p", "a", "b", "c", "e"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.seed_comment(
            "**From:** a\n\n**Type:** vote\n\n**Decision:** d\n**Option:** left\n"
            "\n**From:** b\n"  # a second, conflicting From line — ambiguous identity
        )
        self.vote(10, "d", "right", agent="b")
        self.vote(10, "d", "right", agent="c")
        self.vote(10, "d", "right", agent="e")

        result = self.close(10, "d", agent="p")
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.last_decision_body()
        # The malformed "a" post must not count for either option.
        self.assertIn("right=3", body)
        self.assertNotIn("left=", body)

    def test_a_vote_with_two_option_lines_is_excluded(self):
        self.set_roster(["p", "a", "b"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.seed_comment(
            "**From:** a\n\n**Type:** vote\n\n**Decision:** d\n**Option:** left\n**Option:** right\n"
        )
        self.vote(10, "d", "right", agent="b")

        # roster is ["p","a","b"] (3 agents) — quorum needs 3 distinct votes,
        # and a's malformed vote does not supply one, so this must refuse.
        result = self.close(10, "d", agent="p")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not yet decidable", result.stderr)

    def test_a_wrong_decision_id_vote_never_reaches_the_tally(self):
        self.set_roster(["p", "a", "b"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.propose(10, "other", "up,down", "up", agent="p")
        self.vote(10, "other", "up", agent="a")  # votes on a different decision
        self.vote(10, "d", "left", agent="b")

        result = self.close(10, "d", agent="p")
        self.assertNotEqual(result.returncode, 0)  # only 1 vote actually on 'd'
        self.assertIn("not yet decidable", result.stderr)

    def test_tie_requires_explicit_decision_and_rationale(self):
        self.set_roster(["a", "b"])  # fewer than 3: quorum is "every active agent"
        self.propose(10, "d", "left,right", "left", agent="a")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "right", agent="b")

        bare = self.close(10, "d", agent="a")
        self.assertNotEqual(bare.returncode, 0)
        self.assertIn("--decision", bare.stderr)
        self.assertIn("--rationale", bare.stderr)
        self.assertIn("--authority-effect", bare.stderr)

        resolved = self.close(
            10, "d", agent="a", decision="right",
            rationale="right matches the architecture doc", authority_effect="none",
        )
        self.assertEqual(resolved.returncode, 0, resolved.stderr)
        body = self.last_decision_body()
        self.assertIn("**Chosen:** right", body)
        self.assertIn("**Tie-Break:** right matches the architecture doc", body)
        self.assertIn("**Authority-Effect:** none", body)
        self.assertIn("tied", body)

    def test_off_roster_votes_cannot_fabricate_quorum(self):
        # terra's #436 P1: the roster>=3 branch compared a bare vote count
        # to 3 with no roster check at all — three identities that can post
        # a comment but hold no live lane (no open PR / agent:* issue)
        # could vote and close the round themselves.
        self.set_roster(["p", "a", "b"])  # only p, a, b are active
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="x")  # x, y, z are NOT on the roster
        self.vote(10, "d", "left", agent="y")
        self.vote(10, "d", "left", agent="z")

        result = self.close(10, "d", agent="p")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not yet decidable", result.stderr)
        self.assertEqual(len(self.comments_now()), 4)  # proposal + 3 votes, no decision written

    def test_off_roster_votes_cannot_shift_a_majority_even_with_quorum(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "left", agent="b")
        self.vote(10, "d", "left", agent="c")  # 3 roster votes: clean quorum + majority for left
        self.vote(10, "d", "right", agent="x")  # off-roster: must not touch the tally
        self.vote(10, "d", "right", agent="y")
        self.vote(10, "d", "right", agent="z")

        result = self.close(10, "d", agent="p")
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.last_decision_body()
        self.assertIn("**Chosen:** left", body)
        self.assertIn("left=3", body)
        self.assertNotIn("right=", body)

    def test_close_refuses_an_authority_effect_of_self_on_the_tie_break_path(self):
        # #436 review: the self-interest carve-out had no mechanism — a
        # closer's own declaration is the only thing the script can check,
        # so it must be required and enforced on the record, not assumed.
        self.set_roster(["a", "b"])
        self.propose(10, "d", "left,right", "left", agent="a")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "right", agent="b")

        result = self.close(
            10, "d", agent="a", decision="left",
            rationale="this expands my own review authority", authority_effect="self",
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("refusing", result.stderr)
        self.assertIn("a different closer", result.stderr)
        self.assertEqual(len(self.comments_now()), 3)  # proposal + 2 votes, no decision posted

    def test_close_rejects_an_invalid_authority_effect_value(self):
        self.set_roster(["a", "b"])
        self.propose(10, "d", "left,right", "left", agent="a")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "right", agent="b")

        result = self.close(
            10, "d", agent="a", decision="left", rationale="reason",
            authority_effect="maybe",
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("none", result.stderr)
        self.assertIn("self", result.stderr)

    def test_fewer_than_three_active_agents_quorum_is_all_of_them(self):
        self.set_roster(["a", "b"])
        self.propose(10, "d", "left,right", "left", agent="a")
        self.vote(10, "d", "left", agent="a")
        # only one of the two active agents has voted — quorum not met yet.
        result = self.close(10, "d", agent="a")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not yet decidable", result.stderr)

        self.vote(10, "d", "left", agent="b")
        result2 = self.close(10, "d", agent="a")
        self.assertEqual(result2.returncode, 0, result2.stderr)
        self.assertIn("**Chosen:** left", self.last_decision_body())

    def test_deadline_expiry_with_missing_voters_does_not_stall(self):
        self.set_roster(["p", "a", "b", "c"])  # 3+ active agents: need 3 distinct votes
        # Seed a proposal whose deadline is already in the past.
        self.seed_comment(
            "**From:** p\n\n**Type:** proposal\n\n**Decision:** d\n"
            "**Options:** left,right\n**Recommend:** left\n"
            "**Deadline:** 2020-01-01T00:00:00Z\n\nWhich way?\n"
        )
        self.vote(10, "d", "left", agent="a")
        # only one of three active agents has voted; deadline is already past.

        bare = self.close(10, "d", agent="p")
        self.assertNotEqual(bare.returncode, 0)
        self.assertIn("--decision", bare.stderr)

        resolved = self.close(
            10, "d", agent="p", decision="left",
            rationale="only respondent, matches the recommendation and the architecture doc",
            authority_effect="none",
        )
        self.assertEqual(resolved.returncode, 0, resolved.stderr)
        body = self.last_decision_body()
        self.assertIn("**Chosen:** left", body)
        self.assertIn("not met by the deadline", body)
        self.assertIn("**Authority-Effect:** none", body)

    def test_before_deadline_and_before_quorum_refuses_to_close(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p", deadline_minutes=30)
        self.vote(10, "d", "left", agent="a")

        result = self.close(10, "d", agent="p")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not yet decidable", result.stderr)
        self.assertEqual(len(self.comments_now()), 2)  # proposal + one vote, no decision posted

    def test_close_refuses_to_double_close(self):
        self.set_roster(["a"])
        self.propose(10, "d", "x,y", "x", agent="a")
        self.vote(10, "d", "x", agent="a")
        first = self.close(10, "d", agent="a")
        self.assertEqual(first.returncode, 0, first.stderr)
        second = self.close(10, "d", agent="a")
        self.assertNotEqual(second.returncode, 0)
        self.assertIn("already closed", second.stderr)

    def test_close_refuses_a_decision_flag_that_overrides_a_clear_majority(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="a")
        self.vote(10, "d", "left", agent="b")
        self.vote(10, "d", "right", agent="c")

        result = self.close(10, "d", agent="p", decision="right", rationale="overriding for no good reason")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("overrides a clear quorum majority", result.stderr)
        self.assertEqual(len(self.comments_now()), 4)  # proposal + 3 votes, no decision posted

    def test_the_decision_record_carries_every_required_field(self):
        self.set_roster(["p", "a", "b", "c"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="a", rationale="matches ADR 0006")
        self.vote(10, "d", "left", agent="b")
        self.vote(10, "d", "right", agent="c")

        result = self.close(10, "d", agent="p", owner="a")
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.last_decision_body()
        for field in (
            "**Decision:** d",
            "**Chosen:**",
            "**Tally:**",
            "**Quorum:**",
            "**Deciding-Agent:** p",
            "**Implementation-Owner:** a",
            "**Revisit-If:**",
        ):
            self.assertIn(field, body)
        self.assertIn("Minority votes:", body)
        self.assertIn("- c → right", body)


class VerdictSeparationTest(DecideTestCase):
    """#430 acceptance: decide.sh's votes/decisions must never read as a
    gate.sh PR-review **Verdict:**, and vice versa — they live in separate
    API streams (issue comments vs. PR reviews, #359) and use disjoint
    header vocabularies by construction."""

    VERDICT_PATTERN = r"\*\*Verdict:\*\*\s*(accept(?: with follow-up)?|changes required)\s*$"

    def test_a_vote_body_never_matches_gate_shs_verdict_pattern(self):
        import re

        self.set_roster(["p"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="p", rationale="matches the architecture doc")
        for c in self.comments_now():
            self.assertIsNone(
                re.search(self.VERDICT_PATTERN, c["body"], re.IGNORECASE | re.MULTILINE),
                f"a decide.sh post matched gate.sh's **Verdict:** pattern: {c['body']!r}",
            )

    def test_a_decision_record_never_matches_gate_shs_verdict_pattern(self):
        import re

        self.set_roster(["p"])
        self.propose(10, "d", "left,right", "left", agent="p")
        self.vote(10, "d", "left", agent="p")
        self.close(10, "d", agent="p")
        for c in self.comments_now():
            self.assertIsNone(
                re.search(self.VERDICT_PATTERN, c["body"], re.IGNORECASE | re.MULTILINE),
            )

    def test_board_sh_refuses_a_verdict_typed_post_so_decide_sh_cannot_forge_one(self):
        # board.sh itself is the enforcement point (#359): decide.sh posts
        # exclusively through it, and board.sh hard-refuses --type verdict.
        env = self.env()
        env["CLAIM_AGENT"] = "p"
        board = SCRIPT.with_name("board.sh")
        proc = run_with_bash_path(
            ["bash", str(board), "post", "10", "--agent", "p", "--type", "verdict", "accept"],
            stub_directory=self.bin,
            cwd=self.base,
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("issue comment is invisible to gate.sh", proc.stderr)


class StatusTest(DecideTestCase):
    def test_status_lists_an_open_proposal_and_hides_a_closed_one(self):
        self.set_roster(["p", "a"])
        self.propose(10, "open-one", "x,y", "x", agent="p")
        self.propose(10, "closed-one", "x,y", "x", agent="p")
        self.vote(10, "closed-one", "x", agent="p")
        self.vote(10, "closed-one", "x", agent="a")
        closed = self.close(10, "closed-one", agent="p")
        self.assertEqual(closed.returncode, 0, closed.stderr)

        result = self.run_decide("status", "10")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("open-one", result.stdout)
        self.assertNotIn("closed-one", result.stdout)


if __name__ == "__main__":
    unittest.main()
