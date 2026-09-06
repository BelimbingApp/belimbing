#!/usr/bin/env python3
"""Aggregate per-suite timing JSON files into one GitHub step-summary table (#614)."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


def load_rows(timing_dir: Path) -> list[dict]:
    rows: list[dict] = []
    for path in sorted(timing_dir.glob("*.json")):
        payload = json.loads(path.read_text(encoding="utf-8"))
        for key in ("job", "suite", "wall_seconds", "tests", "assertions"):
            if key not in payload:
                raise SystemExit(f"{path}: missing required key {key!r}")
        rows.append(payload)
    if not rows:
        raise SystemExit(f"no timing JSON files under {timing_dir}")
    return rows


def render(rows: list[dict]) -> str:
    ordered = sorted(rows, key=lambda row: (str(row["job"]), str(row["suite"])))
    lines = [
        "## Per-run timing summary",
        "",
        "Wall time is process elapsed time for each Pest invocation (setup excluded).",
        "",
        "| Job | Suite | Wall (s) | Tests | Assertions |",
        "| --- | --- | ---: | ---: | ---: |",
    ]
    total_wall = 0.0
    total_tests = 0
    total_assertions = 0
    for row in ordered:
        wall = float(row["wall_seconds"])
        tests = int(row["tests"])
        assertions = int(row["assertions"])
        total_wall += wall
        total_tests += tests
        total_assertions += assertions
        lines.append(
            f"| {row['job']} | {row['suite']} | {wall:.3f} | {tests} | {assertions} |"
        )
    lines.append(f"| **Σ** |  | **{total_wall:.3f}** | **{total_tests}** | **{total_assertions}** |")
    lines.append("")
    return "\n".join(lines)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "timing_dir",
        type=Path,
        help="Directory of *.json timing records from record-pest-timing.sh",
    )
    args = parser.parse_args(argv)
    if not args.timing_dir.is_dir():
        raise SystemExit(f"timing directory not found: {args.timing_dir}")
    sys.stdout.write(render(load_rows(args.timing_dir)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
