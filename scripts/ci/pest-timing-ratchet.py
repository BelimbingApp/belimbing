#!/usr/bin/env python3
"""Fail when a Pest suite exceeds both platform timing tolerances (#711)."""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BASELINE_PATH = ROOT / "tests/ci/pest-timing-baseline.json"
RELATIVE_TOLERANCE = 0.25
ABSOLUTE_TOLERANCE_SECONDS = 20.0


def load_json(path: Path) -> object:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise SystemExit(f"missing timing input: {path}") from None
    except (OSError, json.JSONDecodeError) as error:
        raise SystemExit(f"invalid timing input {path}: {error}") from None


def wall_seconds(value: object, *, source: Path) -> float:
    if isinstance(value, bool):
        raise SystemExit(f"{source}: wall_seconds must be a finite number >= 0")
    try:
        seconds = float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        raise SystemExit(f"{source}: wall_seconds must be a finite number >= 0") from None
    if not math.isfinite(seconds) or seconds < 0:
        raise SystemExit(f"{source}: wall_seconds must be a finite number >= 0")
    return seconds


def load_timings(timing_dir: Path) -> dict[str, float]:
    if not timing_dir.is_dir():
        raise SystemExit(f"timing directory not found: {timing_dir}")
    timings: dict[str, float] = {}
    for path in sorted(timing_dir.glob("*.json")):
        payload = load_json(path)
        if not isinstance(payload, dict):
            raise SystemExit(f"{path}: timing record must be an object")
        suite = payload.get("suite")
        if not isinstance(suite, str) or not suite.strip():
            raise SystemExit(f"{path}: suite must be a non-empty string")
        if suite in timings:
            raise SystemExit(f"{path}: duplicate timing for suite {suite!r}")
        timings[suite] = wall_seconds(payload.get("wall_seconds"), source=path)
    if not timings:
        raise SystemExit(f"no timing JSON files under {timing_dir}")
    return timings


def load_baseline(path: Path) -> dict[str, float]:
    payload = load_json(path)
    if not isinstance(payload, dict) or not isinstance(payload.get("suites"), dict):
        raise SystemExit(f"{path}: suites must be a non-empty object")
    suites = payload["suites"]
    if not suites:
        raise SystemExit(f"{path}: suites must be a non-empty object")
    baseline: dict[str, float] = {}
    for suite, row in suites.items():
        if not isinstance(suite, str) or not suite.strip() or not isinstance(row, dict):
            raise SystemExit(f"{path}: invalid suite baseline {suite!r}")
        baseline[suite] = wall_seconds(row.get("wall_seconds"), source=path)
    return baseline


def require_same_suites(current: dict[str, float], baseline: dict[str, float]) -> None:
    missing = sorted(set(baseline) - set(current))
    unknown = sorted(set(current) - set(baseline))
    if missing or unknown:
        details: list[str] = []
        if missing:
            details.append("missing current suites: " + ", ".join(missing))
        if unknown:
            details.append("suites absent from baseline: " + ", ".join(unknown))
        raise SystemExit("timing suite mismatch; " + "; ".join(details))


def check(current: dict[str, float], baseline: dict[str, float]) -> int:
    require_same_suites(current, baseline)
    regressions: list[tuple[str, float, float]] = []
    for suite in sorted(baseline):
        old = baseline[suite]
        new = current[suite]
        if new > old * (1 + RELATIVE_TOLERANCE) and new - old > ABSOLUTE_TOLERANCE_SECONDS:
            regressions.append((suite, old, new))
    if regressions:
        for suite, old, new in regressions:
            print(
                f"Pest timing regression: {suite}: baseline={old:.3f}s current={new:.3f}s",
                file=sys.stderr,
            )
        return 1
    print(
        "PASS: Pest suite timings are within the 25% and 20-second regression tolerances"
    )
    return 0


def write_baseline(path: Path, current: dict[str, float], source: str | None) -> None:
    payload = {
        "schema_version": 1,
        "source": source or "manual timing artifact refresh",
        "tolerances": {
            "relative_percent": 25,
            "absolute_seconds": 20,
        },
        "suites": {
            suite: {"wall_seconds": seconds}
            for suite, seconds in sorted(current.items())
        },
    }
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {path}")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("timing_dir", type=Path)
    parser.add_argument("--baseline", type=Path, default=BASELINE_PATH)
    parser.add_argument("--write-baseline", action="store_true")
    parser.add_argument("--source", help="run URL or other reviewed measurement source")
    args = parser.parse_args(argv)

    current = load_timings(args.timing_dir)
    if args.write_baseline:
        write_baseline(args.baseline, current, args.source)
        return 0
    return check(current, load_baseline(args.baseline))


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
