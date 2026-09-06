#!/usr/bin/env python3
"""Enforce the checked-in dependency audit policy (#617).

Reads docs/ci/dependency-audit-policy.json (or --policy), fails when any
allowlist entry is expired, then evaluates composer and bun audit reports
against min_severity and the non-expired allowlist.

CI runs the live auditors; tests pass fixture JSON via --composer-report /
--bun-report so the expiry and filtering contract stays hermetic.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any

SEVERITY_RANK = {
    "low": 1,
    "moderate": 2,
    "medium": 2,
    "high": 3,
    "critical": 4,
}


def parse_args() -> argparse.Namespace:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--policy",
        type=Path,
        default=root / "docs/ci/dependency-audit-policy.json",
        help="Checked-in policy JSON (min_severity + allowlist).",
    )
    parser.add_argument(
        "--today",
        default=None,
        help="Override UTC calendar date YYYY-MM-DD (tests only).",
    )
    parser.add_argument(
        "--composer-report",
        type=Path,
        default=None,
        help="Use this composer audit JSON instead of running composer.",
    )
    parser.add_argument(
        "--bun-report",
        type=Path,
        default=None,
        help="Use this bun audit JSON instead of running bun.",
    )
    parser.add_argument(
        "--skip-live",
        action="store_true",
        help="Do not invoke live auditors when report paths are omitted (tests).",
    )
    return parser.parse_args()


def utc_today(override: str | None) -> date:
    if override:
        return date.fromisoformat(override)
    return datetime.now(timezone.utc).date()


def load_policy(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise SystemExit(f"policy must be a JSON object: {path}")
    min_severity = data.get("min_severity")
    if not isinstance(min_severity, str) or min_severity.lower() not in SEVERITY_RANK:
        raise SystemExit(
            "policy.min_severity must be one of: low, moderate|medium, high, critical"
        )
    allowlist = data.get("allowlist", [])
    if not isinstance(allowlist, list):
        raise SystemExit("policy.allowlist must be a list")
    return data


def normalize_severity(value: str | None) -> str:
    if not value:
        return "high"
    key = value.strip().lower()
    if key not in SEVERITY_RANK:
        return "high"
    return "moderate" if key == "medium" else key


def severity_at_least(severity: str, minimum: str) -> bool:
    return SEVERITY_RANK[normalize_severity(severity)] >= SEVERITY_RANK[normalize_severity(minimum)]


def check_allowlist_expiry(allowlist: list[Any], today: date) -> list[str]:
    errors: list[str] = []
    for index, entry in enumerate(allowlist):
        if not isinstance(entry, dict):
            errors.append(f"allowlist[{index}] must be an object")
            continue
        advisory_id = entry.get("id")
        reason = entry.get("reason")
        expires = entry.get("expires")
        ecosystem = entry.get("ecosystem")
        if not isinstance(advisory_id, str) or not advisory_id.strip():
            errors.append(f"allowlist[{index}].id must be a non-empty string")
        if not isinstance(reason, str) or not reason.strip():
            errors.append(f"allowlist[{index}].reason must be a non-empty string")
        if ecosystem not in {"composer", "bun"}:
            errors.append(f"allowlist[{index}].ecosystem must be composer or bun")
        if not isinstance(expires, str):
            errors.append(f"allowlist[{index}].expires must be YYYY-MM-DD")
            continue
        try:
            expiry = date.fromisoformat(expires)
        except ValueError:
            errors.append(f"allowlist[{index}].expires must be YYYY-MM-DD")
            continue
        if expiry < today:
            errors.append(
                f"allowlist entry {advisory_id!r} expired on {expires} "
                f"(today UTC {today.isoformat()}); remove it or refresh with a new review"
            )
    return errors


def active_allowlist_ids(allowlist: list[Any], today: date, ecosystem: str) -> set[str]:
    ids: set[str] = set()
    for entry in allowlist:
        if not isinstance(entry, dict):
            continue
        if entry.get("ecosystem") != ecosystem:
            continue
        expires = entry.get("expires")
        advisory_id = entry.get("id")
        if not isinstance(expires, str) or not isinstance(advisory_id, str):
            continue
        try:
            expiry = date.fromisoformat(expires)
        except ValueError:
            continue
        if expiry >= today:
            ids.add(advisory_id.strip())
    return ids


def run_json_command(command: list[str]) -> Any:
    completed = subprocess.run(
        command,
        check=False,
        capture_output=True,
        text=True,
    )
    stdout = completed.stdout.strip()
    if not stdout:
        raise SystemExit(
            f"{' '.join(command)} produced no JSON "
            f"(exit {completed.returncode}): {completed.stderr.strip()}"
        )
    try:
        return json.loads(stdout)
    except json.JSONDecodeError as error:
        raise SystemExit(
            f"{' '.join(command)} returned invalid JSON: {error}\n{stdout[:500]}"
        ) from error


def load_report(path: Path | None, live_command: list[str], skip_live: bool) -> Any:
    if path is not None:
        return json.loads(path.read_text(encoding="utf-8"))
    if skip_live:
        return {}
    return run_json_command(live_command)


def composer_findings(report: Any) -> list[dict[str, str]]:
    findings: list[dict[str, str]] = []
    advisories = report.get("advisories", []) if isinstance(report, dict) else []
    if isinstance(advisories, list):
        packages: list[tuple[str, Any]] = [("", item) for item in advisories]
    elif isinstance(advisories, dict):
        packages = []
        for package, items in advisories.items():
            if isinstance(items, list):
                packages.extend((package, item) for item in items)
            else:
                packages.append((package, items))
    else:
        return findings

    for package, item in packages:
        if not isinstance(item, dict):
            continue
        advisory_id = (
            item.get("advisoryId")
            or item.get("advisory_id")
            or item.get("id")
            or ""
        )
        cves = item.get("cve") or item.get("cves") or []
        if isinstance(cves, str):
            cves = [cves]
        if not isinstance(cves, list):
            cves = []
        severity = normalize_severity(str(item.get("severity") or "high"))
        title = str(item.get("title") or item.get("link") or advisory_id or package)
        findings.append(
            {
                "ecosystem": "composer",
                "package": str(package or item.get("packageName") or ""),
                "id": str(advisory_id),
                "cves": ",".join(str(cve) for cve in cves if cve),
                "severity": severity,
                "title": title,
            }
        )
    return findings


def bun_findings(report: Any) -> list[dict[str, str]]:
    findings: list[dict[str, str]] = []
    if not isinstance(report, dict):
        return findings

    # bun audit --json shapes vary: top-level advisory map, or { advisories: [...] }
    candidates: list[Any] = []
    if isinstance(report.get("advisories"), list):
        candidates.extend(report["advisories"])
    elif isinstance(report.get("advisories"), dict):
        for value in report["advisories"].values():
            if isinstance(value, list):
                candidates.extend(value)
            else:
                candidates.append(value)
    else:
        for key, value in report.items():
            if key in {"name", "count", "vulnerabilities"}:
                continue
            if isinstance(value, list):
                candidates.extend(value)
            elif isinstance(value, dict) and (
                "severity" in value or "cve" in value or "id" in value or "advisory" in value
            ):
                candidates.append(value)

    for item in candidates:
        if not isinstance(item, dict):
            continue
        advisory_id = str(
            item.get("id")
            or item.get("advisoryId")
            or item.get("cve")
            or item.get("url")
            or ""
        )
        severity = normalize_severity(str(item.get("severity") or "high"))
        package = str(item.get("package") or item.get("name") or "")
        title = str(item.get("title") or item.get("url") or advisory_id or package)
        cve = item.get("cve") or item.get("cves") or []
        if isinstance(cve, str):
            cve_list = [cve]
        elif isinstance(cve, list):
            cve_list = [str(x) for x in cve if x]
        else:
            cve_list = []
        findings.append(
            {
                "ecosystem": "bun",
                "package": package,
                "id": advisory_id,
                "cves": ",".join(cve_list),
                "severity": severity,
                "title": title,
            }
        )
    return findings


def is_allowlisted(finding: dict[str, str], allowed: set[str]) -> bool:
    if finding["id"] and finding["id"] in allowed:
        return True
    for cve in finding["cves"].split(","):
        if cve and cve in allowed:
            return True
    return False


def main() -> int:
    args = parse_args()
    today = utc_today(args.today)
    policy = load_policy(args.policy)
    allowlist = policy.get("allowlist", [])
    assert isinstance(allowlist, list)
    min_severity = str(policy["min_severity"])

    expiry_errors = check_allowlist_expiry(allowlist, today)
    if expiry_errors:
        for error in expiry_errors:
            print(error, file=sys.stderr)
        return 1

    composer_report = load_report(
        args.composer_report,
        ["composer", "audit", "--locked", "--format=json", "--no-interaction"],
        args.skip_live,
    )
    bun_report = load_report(
        args.bun_report,
        ["bun", "audit", "--json"],
        args.skip_live,
    )

    composer_allowed = active_allowlist_ids(allowlist, today, "composer")
    bun_allowed = active_allowlist_ids(allowlist, today, "bun")

    blocking: list[dict[str, str]] = []
    for finding in composer_findings(composer_report):
        if not severity_at_least(finding["severity"], min_severity):
            continue
        if is_allowlisted(finding, composer_allowed):
            continue
        blocking.append(finding)
    for finding in bun_findings(bun_report):
        if not severity_at_least(finding["severity"], min_severity):
            continue
        if is_allowlisted(finding, bun_allowed):
            continue
        blocking.append(finding)

    if blocking:
        print(
            f"Dependency audit failed: {len(blocking)} finding(s) at or above "
            f"min_severity={min_severity}",
            file=sys.stderr,
        )
        for finding in blocking:
            print(
                f"- [{finding['ecosystem']}] {finding['severity']} "
                f"{finding['id'] or '(no id)'} {finding['package']} — {finding['title']}",
                file=sys.stderr,
            )
        return 1

    print(
        f"Dependency audit passed (min_severity={min_severity}, "
        f"allowlist={len(allowlist)}, today={today.isoformat()})."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
