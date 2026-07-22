#!/usr/bin/env python3
"""Create a safe, hierarchical human-tester audit run directory."""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path


def env_value(repo_root: Path, key: str) -> str | None:
    env_path = repo_root / ".env"
    if not env_path.is_file():
        return None
    pattern = re.compile(rf"^\s*{re.escape(key)}\s*=\s*(.*?)\s*$")
    for line in env_path.read_text(encoding="utf-8").splitlines():
        match = pattern.match(line)
        if match:
            return match.group(1).strip("'\"")
    return None


def safe_segment(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    return value or "target"


def safe_target_path(value: str) -> Path:
    segments = [safe_segment(part) for part in re.split(r"[\\/]+", value) if part.strip()]
    if not segments:
        segments = ["target"]
    return Path(*segments)


def choose_run_dir(parent: Path, stamp: str) -> Path:
    candidate = parent / stamp
    suffix = 1
    while candidate.exists():
        candidate = parent / f"{stamp}-{suffix:02d}"
        suffix += 1
    return candidate


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--target", required=True, help="Human-readable page or menu name")
    parser.add_argument("--target-path", required=True, help="Visible hierarchy, e.g. people/leave-requests")
    parser.add_argument("--base-url", help="Override .env APP_URL")
    parser.add_argument("--environment", help="Override .env APP_ENV")
    parser.add_argument("--role", default="Test user")
    parser.add_argument(
        "--tester-identity",
        required=True,
        help="Exact provider/model-effort identity, e.g. codex/sol-high",
    )
    parser.add_argument(
        "--browser-tool",
        required=True,
        help="Actual selected driver, e.g. in-app Browser or Playwright standalone",
    )
    parser.add_argument(
        "--browser-fallback-reason",
        help="Concrete in-app Browser failure when another driver is selected",
    )
    parser.add_argument("--output-root", default="storage/app/qa/human-tester-audit")
    args = parser.parse_args()

    repo_root = Path.cwd()
    base_url = args.base_url or env_value(repo_root, "APP_URL") or ""
    environment = args.environment or env_value(repo_root, "APP_ENV") or "unknown"
    if not re.fullmatch(r"[^/\s]+/[^/\s]+-[^/\s]+", args.tester_identity):
        parser.error("--tester-identity must use provider/model-effort format")
    if args.browser_tool.casefold() != "in-app browser" and not args.browser_fallback_reason:
        parser.error("--browser-fallback-reason is required for a fallback driver")
    target_path = safe_target_path(args.target_path)
    root = Path(args.output_root) / target_path
    local_now = datetime.now().astimezone()
    stamp = local_now.strftime("%Y%m%d-%H%M")
    run_dir = choose_run_dir(root, stamp)
    screenshots = run_dir / "screenshots"
    screenshots.mkdir(parents=True, exist_ok=False)

    manifest = {
        "meta": {
            "title": f"{args.target} human tester audit",
            "target": args.target,
            "target_path": target_path.as_posix(),
            "tester_identity": args.tester_identity,
            "browser_tool": args.browser_tool,
            **(
                {"browser_fallback_reason": args.browser_fallback_reason}
                if args.browser_fallback_reason
                else {}
            ),
            "base_url": base_url,
            "environment": environment,
            "mutation_policy": "Set after environment classification.",
            "role": args.role,
            "started_at": local_now.isoformat(timespec="seconds"),
            "viewports": ["1440x900", "390x844"],
            "scope_note": "Replace with the confirmed audit scope.",
            "notes": [],
        },
        "workflow_assessment": {
            "applicable": None,
            "rationale": "Replace after inspecting the target.",
        },
        "role_sessions": [],
        "workflow_paths": [],
        "pages": [],
        "findings": [],
    }
    manifest_path = run_dir / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    identity_slug = safe_segment(args.tester_identity)
    report_path = run_dir / f"{target_path.name}-{run_dir.name}-{identity_slug}.html"
    print(json.dumps({"run_dir": str(run_dir), "manifest": str(manifest_path), "report": str(report_path)}))


if __name__ == "__main__":
    main()
