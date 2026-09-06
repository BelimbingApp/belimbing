#!/usr/bin/env python3
"""Ratchet platform line coverage against a checked-in baseline (#629).

Combines PHPUnit/Pest Clover reports by source file and statement line identity.
A statement is covered if any shard executed it. PR checks fail when the line rate falls
more than ``tolerance_pp`` percentage points below the baseline. On main, the
baseline is raised (never lowered) when the measured rate is higher.
"""

from __future__ import annotations

import argparse
import json
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

DEFAULT_BASELINE = Path("tests/ci/platform-coverage-baseline.json")
DEFAULT_TOLERANCE_PP = 0.05


def parse_clover(path: Path) -> dict[tuple[str, int], bool]:
    """Read statement identities, ignoring repeated aggregate/class metrics."""
    root = ET.parse(path).getroot()
    project = root.find("project")
    if project is None:
        raise SystemExit(f"clover report missing project element: {path}")
    statements: dict[tuple[str, int], bool] = {}
    for file in project.iter("file"):
        name = file.get("name")
        if not name:
            raise SystemExit(f"clover file missing name: {path}")
        for line in file.findall("line"):
            if line.get("type") != "stmt":
                continue
            number = int(line.get("num") or 0)
            count = int(line.get("count") or 0)
            if number < 1 or count < 0:
                raise SystemExit(f"invalid Clover statement in {path}: {name}")
            key = (name, number)
            statements[key] = statements.get(key, False) or count > 0
    if not statements:
        raise SystemExit(f"clover report has no statement line identities: {path}")
    return statements


def combined_line_rate(reports: list[Path]) -> tuple[float, int, int]:
    lines: dict[tuple[str, int], bool] = {}
    for report in reports:
        for identity, executed in parse_clover(report).items():
            lines[identity] = lines.get(identity, False) or executed
    statements = len(lines)
    covered = sum(lines.values())
    if statements < 1:
        raise SystemExit("combined clover reports have no statements")
    rate = 100.0 * covered / statements
    return rate, covered, statements


def load_baseline(path: Path) -> dict:
    if not path.is_file():
        raise SystemExit(f"missing coverage baseline: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if "line_rate" not in data:
        raise SystemExit(f"baseline missing line_rate: {path}")
    return data


def write_baseline(path: Path, line_rate: float, covered: int, statements: int, tolerance_pp: float) -> None:
    payload = {
        "line_rate": round(line_rate, 4),
        "coveredstatements": covered,
        "statements": statements,
        "tolerance_pp": tolerance_pp,
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")


def cmd_measure(args: argparse.Namespace) -> int:
    rate, covered, statements = combined_line_rate(args.reports)
    print(f"line_rate={rate:.4f} covered={covered} statements={statements}")
    return 0


def cmd_check(args: argparse.Namespace) -> int:
    baseline = load_baseline(args.baseline)
    rate, covered, statements = combined_line_rate(args.reports)
    floor = float(baseline["line_rate"]) - float(baseline.get("tolerance_pp", args.tolerance_pp))
    print(
        f"measured={rate:.4f} baseline={float(baseline['line_rate']):.4f} "
        f"floor={floor:.4f} covered={covered}/{statements}"
    )
    if rate + 1e-9 < floor:
        print(
            f"::error::Platform line coverage {rate:.4f}% is below the baseline floor "
            f"{floor:.4f}% (baseline {float(baseline['line_rate']):.4f}% − "
            f"tolerance {float(baseline.get('tolerance_pp', args.tolerance_pp)):.4f}pp).",
            file=sys.stderr,
        )
        return 1
    return 0


def cmd_update(args: argparse.Namespace) -> int:
    baseline_path = args.baseline
    rate, covered, statements = combined_line_rate(args.reports)
    tolerance = args.tolerance_pp
    if baseline_path.is_file():
        existing = load_baseline(baseline_path)
        tolerance = float(existing.get("tolerance_pp", tolerance))
        previous = float(existing["line_rate"])
        if rate + 1e-9 < previous:
            print(
                f"measured={rate:.4f} below baseline={previous:.4f}; leaving baseline unchanged"
            )
            return 0
        if abs(rate - previous) < 1e-9:
            print(f"measured={rate:.4f} matches baseline; no update")
            return 0
    write_baseline(baseline_path, rate, covered, statements, tolerance)
    print(f"updated {baseline_path} to line_rate={rate:.4f}")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    def add_report_args(p: argparse.ArgumentParser) -> None:
        p.add_argument(
            "reports",
            nargs="+",
            type=Path,
            help="Clover XML report paths (statement identities are unioned)",
        )

    measure = sub.add_parser("measure", help="Print combined line coverage")
    add_report_args(measure)
    measure.set_defaults(func=cmd_measure)

    check = sub.add_parser("check", help="Fail when coverage drops below baseline − tolerance")
    add_report_args(check)
    check.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    check.add_argument("--tolerance-pp", type=float, default=DEFAULT_TOLERANCE_PP)
    check.set_defaults(func=cmd_check)

    update = sub.add_parser("update", help="Raise the checked-in baseline when coverage improves")
    add_report_args(update)
    update.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    update.add_argument("--tolerance-pp", type=float, default=DEFAULT_TOLERANCE_PP)
    update.set_defaults(func=cmd_update)

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
