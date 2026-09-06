#!/usr/bin/env python3
"""Upsert one PR comment with timing summary + coverage delta (#670).

Idempotent by a stable HTML marker line. Replacing the previous comment keeps
PR threads from accumulating one summary per suite run.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Protocol

MARKER = "<!-- belimbing-ci-run-summary -->"
SCRIPT_DIR = Path(__file__).resolve().parent


def _load_sibling(name: str, filename: str) -> Any:
    path = SCRIPT_DIR / filename
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"cannot load {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


timing = _load_sibling("aggregate_pest_timing", "aggregate-pest-timing.py")
ratchet = _load_sibling("platform_coverage_ratchet", "platform-coverage-ratchet.py")


def render_coverage_delta(reports: list[Path], baseline_path: Path) -> str:
    baseline = ratchet.load_baseline(baseline_path)
    measured, covered, statements = ratchet.combined_line_rate(reports)
    base_rate = float(baseline["line_rate"])
    delta = measured - base_rate
    sign = "+" if delta >= 0 else ""
    lines = [
        "## Coverage delta",
        "",
        "Combined platform line coverage versus the checked-in baseline "
        "(`tests/ci/platform-coverage-baseline.json`).",
        "",
        "| | Rate | Covered / statements |",
        "| --- | ---: | ---: |",
        f"| Baseline | {base_rate:.4f}% | "
        f"{int(baseline.get('coveredstatements', 0))}/{int(baseline.get('statements', 0))} |",
        f"| Measured | {measured:.4f}% | {covered}/{statements} |",
        f"| Δ | {sign}{delta:.4f} pp |  |",
        "",
    ]
    return "\n".join(lines)


def render_comment(timing_dir: Path, reports: list[Path], baseline_path: Path) -> str:
    timing_body = timing.render(timing.load_rows(timing_dir)).rstrip() + "\n"
    coverage_body = render_coverage_delta(reports, baseline_path)
    return f"{MARKER}\n\n{timing_body}\n{coverage_body}"


class CommentApi(Protocol):
    def list_comments(self) -> list[dict[str, Any]]: ...

    def create_comment(self, body: str) -> dict[str, Any]: ...

    def update_comment(self, comment_id: int, body: str) -> dict[str, Any]: ...


class GitHubCommentApi:
    def __init__(self, repo: str, pr: int, token: str, api_url: str = "https://api.github.com") -> None:
        if "/" not in repo:
            raise SystemExit(f"repo must be owner/name, got {repo!r}")
        self.repo = repo
        self.pr = pr
        self.token = token
        self.api_url = api_url.rstrip("/")

    def _request(self, method: str, path: str, payload: dict[str, Any] | None = None) -> Any:
        url = f"{self.api_url}{path}"
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=data,
            method=method,
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": f"Bearer {self.token}",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "belimbing-ci-run-summary",
                "Content-Type": "application/json",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                body = resp.read().decode("utf-8")
                return json.loads(body) if body else None
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise SystemExit(f"GitHub API {method} {path} failed: {exc.code} {detail}") from exc

    def list_comments(self) -> list[dict[str, Any]]:
        comments: list[dict[str, Any]] = []
        page = 1
        while True:
            qs = urllib.parse.urlencode({"per_page": 100, "page": page})
            batch = self._request("GET", f"/repos/{self.repo}/issues/{self.pr}/comments?{qs}")
            if not isinstance(batch, list) or not batch:
                break
            comments.extend(batch)
            if len(batch) < 100:
                break
            page += 1
        return comments

    def create_comment(self, body: str) -> dict[str, Any]:
        result = self._request("POST", f"/repos/{self.repo}/issues/{self.pr}/comments", {"body": body})
        if not isinstance(result, dict):
            raise SystemExit("GitHub API create comment returned a non-object")
        return result

    def update_comment(self, comment_id: int, body: str) -> dict[str, Any]:
        result = self._request("PATCH", f"/repos/{self.repo}/issues/comments/{comment_id}", {"body": body})
        if not isinstance(result, dict):
            raise SystemExit("GitHub API update comment returned a non-object")
        return result


class MemoryCommentApi:
    """In-process stand-in used by the fixture acceptance test."""

    def __init__(self) -> None:
        self._comments: list[dict[str, Any]] = []
        self._next_id = 1

    def list_comments(self) -> list[dict[str, Any]]:
        return [dict(c) for c in self._comments]

    def create_comment(self, body: str) -> dict[str, Any]:
        comment = {"id": self._next_id, "body": body}
        self._next_id += 1
        self._comments.append(comment)
        return dict(comment)

    def update_comment(self, comment_id: int, body: str) -> dict[str, Any]:
        for comment in self._comments:
            if int(comment["id"]) == int(comment_id):
                comment["body"] = body
                return dict(comment)
        raise SystemExit(f"memory API: comment {comment_id} not found")


def find_marked_comment(comments: list[dict[str, Any]]) -> dict[str, Any] | None:
    matches = [c for c in comments if MARKER in str(c.get("body") or "")]
    if not matches:
        return None
    # Prefer the oldest marked comment so a stray second copy is overwritten
    # onto the first and can be detected by tests counting list length.
    return min(matches, key=lambda c: int(c["id"]))


def upsert_comment(api: CommentApi, body: str) -> tuple[str, dict[str, Any]]:
    existing = find_marked_comment(api.list_comments())
    if existing is None:
        created = api.create_comment(body)
        return "created", created
    updated = api.update_comment(int(existing["id"]), body)
    return "updated", updated


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--timing-dir", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("reports", nargs="+", type=Path, help="Clover XML reports")
    parser.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    parser.add_argument("--pr", type=int, default=int(os.environ["PR_NUMBER"]) if os.environ.get("PR_NUMBER") else 0)
    parser.add_argument("--token", default=os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN") or "")
    parser.add_argument("--api-url", default=os.environ.get("GITHUB_API_URL", "https://api.github.com"))
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print the comment body to stdout and exit without calling GitHub",
    )
    parser.add_argument(
        "--memory-fixture",
        action="store_true",
        help="Run the two-pass upsert acceptance against an in-memory API and exit",
    )
    return parser


def run_memory_fixture(timing_dir: Path, reports: list[Path], baseline: Path) -> int:
    api = MemoryCommentApi()
    body1 = render_comment(timing_dir, reports, baseline)
    action1, first = upsert_comment(api, body1)
    body2 = body1.replace("## Per-run timing summary", "## Per-run timing summary (rerun)")
    # Keep the marker; change visible content so an update is observable.
    action2, second = upsert_comment(api, body2)
    comments = api.list_comments()
    if action1 != "created" or action2 != "updated":
        print(f"unexpected actions: {action1!r} then {action2!r}", file=sys.stderr)
        return 1
    if len(comments) != 1:
        print(f"expected one comment after two upserts, got {len(comments)}", file=sys.stderr)
        return 1
    if comments[0]["id"] != first["id"] or comments[0]["id"] != second["id"]:
        print("comment id changed across upserts", file=sys.stderr)
        return 1
    if MARKER not in comments[0]["body"] or "(rerun)" not in comments[0]["body"]:
        print("updated body missing marker or rerun marker", file=sys.stderr)
        return 1
    print("memory-fixture ok: one comment updated across two runs")
    return 0


def main(argv: list[str]) -> int:
    args = build_parser().parse_args(argv)
    if not args.timing_dir.is_dir():
        raise SystemExit(f"timing directory not found: {args.timing_dir}")
    if not args.baseline.is_file():
        raise SystemExit(f"baseline not found: {args.baseline}")
    for report in args.reports:
        if not report.is_file():
            raise SystemExit(f"coverage report not found: {report}")

    if args.memory_fixture:
        return run_memory_fixture(args.timing_dir, args.reports, args.baseline)

    body = render_comment(args.timing_dir, args.reports, args.baseline)
    if args.dry_run:
        sys.stdout.write(body)
        return 0

    if not args.repo or args.pr < 1 or not args.token:
        raise SystemExit("upsert requires --repo, --pr, and a GH_TOKEN/GITHUB_TOKEN (or --dry-run)")

    api = GitHubCommentApi(args.repo, args.pr, args.token, api_url=args.api_url)
    action, comment = upsert_comment(api, body)
    comment_id = comment.get("id")
    print(f"{action} PR #{args.pr} CI summary comment id={comment_id}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
