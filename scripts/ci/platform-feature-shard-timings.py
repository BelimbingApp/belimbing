#!/usr/bin/env python3
"""Refresh Unit or Feature directory estimates from lane suite timings.

Reads per-suite JSON produced by scripts/ci/record-pest-timing.sh for the selected suite
lanes, maps each lane onto its shard directories, and rewrites per-directory
wall_seconds. Ratios within a shard follow the previous summary when present;
otherwise the measured wall is split evenly. Refuses when a selected suite directory
has no measurement or a measured directory is absent from its test surface.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = Path(__file__).resolve().parent


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def suite_directories(surface: Path, suite: str) -> set[str]:
    if not surface.is_dir():
        raise SystemExit(f'{suite} surface missing: {surface}')
    dirs = {path.name for path in surface.iterdir() if path.is_dir()}
    loose = sorted(path.name for path in surface.glob('*Test.php'))
    if loose:
        raise SystemExit(
            f'{suite} tests must live under a first-level directory; found loose '
            f'files: {", ".join(loose)}'
        )
    return dirs


def load_shards(path: Path) -> dict[str, list[str]]:
    payload = load_json(path)
    shards = payload.get('shards')
    if not isinstance(shards, dict) or not shards:
        raise SystemExit(f'{path}: shards must be a non-empty object')
    normalized: dict[str, list[str]] = {}
    for name, directories in shards.items():
        if not isinstance(directories, list) or not directories:
            raise SystemExit(f'{path}: shard {name!r} must be a non-empty list')
        cleaned: list[str] = []
        for entry in directories:
            if not isinstance(entry, str) or entry.strip() == '' or '/' in entry:
                raise SystemExit(f'{path}: shard {name!r} has invalid directory {entry!r}')
            cleaned.append(entry)
        normalized[str(name)] = cleaned
    return normalized


def load_suite_timings(timing_dir: Path, selected_suite: str) -> dict[str, float]:
    """Map suite label (Unit-a, Feature-a, ...) -> wall_seconds."""
    if not timing_dir.is_dir():
        raise SystemExit(f'timing directory not found: {timing_dir}')
    by_suite: dict[str, float] = {}
    for path in sorted(timing_dir.glob('*.json')):
        payload = load_json(path)
        suite = payload.get('suite')
        if not isinstance(suite, str) or not suite.startswith(f'{selected_suite}-'):
            continue
        if 'wall_seconds' not in payload:
            raise SystemExit(f'{path}: missing wall_seconds')
        wall = float(payload['wall_seconds'])
        if wall < 0:
            raise SystemExit(f'{path}: wall_seconds must be >= 0')
        # Last write wins if duplicates; prefer explicit collision refusal.
        if suite in by_suite and by_suite[suite] != wall:
            raise SystemExit(f'{path}: conflicting wall_seconds for suite {suite!r}')
        by_suite[suite] = wall
    if not by_suite:
        raise SystemExit(f'no {selected_suite}-* suite timing JSON under {timing_dir}')
    return by_suite


def prior_walls(summary: dict) -> dict[str, float]:
    directories = summary.get('directories')
    if not isinstance(directories, dict):
        return {}
    walls: dict[str, float] = {}
    for name, row in directories.items():
        if isinstance(row, dict) and 'wall_seconds' in row:
            walls[str(name)] = float(row['wall_seconds'])
    return walls


def prior_test_files(summary: dict) -> dict[str, int]:
    directories = summary.get('directories')
    if not isinstance(directories, dict):
        return {}
    counts: dict[str, int] = {}
    for name, row in directories.items():
        if isinstance(row, dict) and 'test_files' in row:
            counts[str(name)] = int(row['test_files'])
    return counts


def distribute(shard_wall: float, directories: list[str], priors: dict[str, float]) -> dict[str, float]:
    weights = [max(priors.get(name, 0.0), 0.0) for name in directories]
    total = sum(weights)
    if total <= 0:
        share = shard_wall / len(directories)
        return {name: round(share, 3) for name in directories}
    return {
        name: round(shard_wall * (weight / total), 3)
        for name, weight in zip(directories, weights, strict=True)
    }


def refresh(
    *,
    timing_dir: Path,
    shards_path: Path,
    summary_path: Path,
    root: Path,
    suite: str,
) -> dict:
    surface = root / 'tests' / suite
    present = suite_directories(surface, suite)
    shards = load_shards(shards_path)
    suite_walls = load_suite_timings(timing_dir, suite)

    summary = load_json(summary_path) if summary_path.is_file() else {
        'source': '',
        'measured_at': '',
        'note': '',
        'directories': {},
    }
    priors = prior_walls(summary)
    test_files = prior_test_files(summary)

    measured_dirs: dict[str, float] = {}
    claimed: set[str] = set()

    for shard_id, directories in shards.items():
        lane = f'{suite}-{shard_id}'
        if lane not in suite_walls:
            raise SystemExit(f'missing suite timing for {lane}')
        for name in directories:
            if name in claimed:
                raise SystemExit(f'directory {name!r} appears in more than one shard')
            claimed.add(name)
        measured_dirs.update(distribute(suite_walls[lane], directories, priors))

    missing = sorted(present - set(measured_dirs))
    if missing:
        raise SystemExit(
            f'{suite} directory has no measurement: ' + ', '.join(missing)
        )

    unknown = sorted(set(measured_dirs) - present)
    if unknown:
        raise SystemExit(
            'measured directory no longer exists: ' + ', '.join(unknown)
        )

    directories_out: dict[str, dict] = {}
    for name in sorted(present):
        row: dict = {'wall_seconds': measured_dirs[name]}
        if name in test_files:
            row['test_files'] = test_files[name]
        elif isinstance(summary.get('directories'), dict):
            prior_row = summary['directories'].get(name)
            if isinstance(prior_row, dict) and 'test_files' in prior_row:
                row['test_files'] = int(prior_row['test_files'])
        directories_out[name] = row

    summary['source'] = f'ci-{suite.lower()}-lane-timings'
    summary['measured_at'] = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    summary['note'] = (
        f'Estimated wall times for first-level tests/{suite} directories, allocated '
        f'from {suite}-* lane timings in proportion to prior directory weights '
        '(or equally without prior weights); not direct directory measurements. '
        f'Used to regenerate platform-{suite.lower()}-shards.json.'
    )
    summary['directories'] = directories_out
    return summary


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--timing-dir',
        type=Path,
        required=True,
        help='directory of *.json records from record-pest-timing.sh',
    )
    parser.add_argument('--suite', choices=('Feature', 'Unit'), default='Feature')
    parser.add_argument('--shards-file', type=Path)
    parser.add_argument('--summary-file', type=Path)
    parser.add_argument(
        '--root',
        type=Path,
        default=ROOT,
        help='repository root containing tests/<suite>',
    )
    parser.add_argument(
        '--write',
        action='store_true',
        help='write --summary-file in place (default: print JSON to stdout)',
    )
    args = parser.parse_args(argv)
    args.shards_file = args.shards_file or SCRIPT_DIR / f'platform-{args.suite.lower()}-shards.json'
    args.summary_file = args.summary_file or SCRIPT_DIR / f'platform-{args.suite.lower()}-shard-timings.json'

    summary = refresh(
        timing_dir=args.timing_dir,
        shards_path=args.shards_file,
        summary_path=args.summary_file,
        root=args.root,
        suite=args.suite,
    )
    text = json.dumps(summary, indent=2) + '\n'
    if args.write:
        args.summary_file.write_text(text, encoding='utf-8')
        print(f'wrote {args.summary_file}')
    else:
        sys.stdout.write(text)
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
