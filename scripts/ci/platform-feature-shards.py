#!/usr/bin/env python3
"""Emit Unit or Feature suite shard paths for platform CI (#576 / #626).

Each suite remains one logical suite in phpunit.xml. CI runs disjoint
directory shards concurrently. Membership is generated from the checked-in
directory timing summary (#626) and validated so every selected *Test.php file
belongs to exactly one shard.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TIMINGS_PATH = Path(__file__).resolve().with_name('platform-feature-shard-timings.json')


def load_shards(path: Path) -> dict[str, list[str]]:
    payload = json.loads(path.read_text(encoding='utf-8'))
    shards = payload.get('shards')
    if not isinstance(shards, dict) or not shards:
        raise SystemExit(f'{path}: shards must be a non-empty object')
    normalized: dict[str, list[str]] = {}
    for name, directories in shards.items():
        if not isinstance(name, str) or not name.strip():
            raise SystemExit(f'{path}: shard names must be non-empty strings')
        if not isinstance(directories, list) or not directories:
            raise SystemExit(f'{path}: shard {name!r} must be a non-empty list')
        cleaned: list[str] = []
        for entry in directories:
            if not isinstance(entry, str) or entry.strip() == '' or '/' in entry or entry in ('.', '..'):
                raise SystemExit(f'{path}: shard {name!r} has invalid directory {entry!r}')
            cleaned.append(entry)
        if cleaned != sorted(cleaned):
            raise SystemExit(f'{path}: shard {name!r} directories must be sorted')
        if len(cleaned) != len(set(cleaned)):
            raise SystemExit(f'{path}: shard {name!r} directories must be unique')
        normalized[name] = cleaned
    return normalized


def load_timings(path: Path) -> dict[str, float]:
    payload = json.loads(path.read_text(encoding='utf-8'))
    directories = payload.get('directories')
    if not isinstance(directories, dict) or not directories:
        raise SystemExit(f'{path}: directories must be a non-empty object')
    timings: dict[str, float] = {}
    for name, row in directories.items():
        if not isinstance(name, str) or not name.strip():
            raise SystemExit(f'{path}: directory names must be non-empty strings')
        if not isinstance(row, dict) or 'wall_seconds' not in row:
            raise SystemExit(f'{path}: directory {name!r} must include wall_seconds')
        timings[name] = float(row['wall_seconds'])
        if timings[name] < 0:
            raise SystemExit(f'{path}: directory {name!r} wall_seconds must be >= 0')
    return timings


def feature_directories(surface: Path) -> set[str]:
    if not surface.is_dir():
        raise SystemExit(f'Test surface missing: {surface}')
    dirs = {path.name for path in surface.iterdir() if path.is_dir()}
    loose = sorted(path.name for path in surface.glob('*Test.php'))
    if loose:
        raise SystemExit(
            'Tests must live under a first-level directory so shards can '
            f'own them; found loose files: {", ".join(loose)}'
        )
    return dirs


def feature_test_files(surface: Path) -> list[Path]:
    return sorted(path for path in surface.rglob('*Test.php') if path.is_file())


def balance_from_timings(timings: dict[str, float], shard_names: list[str]) -> dict[str, list[str]]:
    if len(shard_names) < 2:
        raise SystemExit('need at least two shard names to balance')
    # Place each next longest directory into the least-loaded shard.
    ordered = sorted(timings.items(), key=lambda item: (-item[1], item[0]))
    loads = {name: 0.0 for name in shard_names}
    bins: dict[str, list[str]] = {name: [] for name in shard_names}
    for directory, wall in ordered:
        target = min(shard_names, key=lambda name: (loads[name], name))
        bins[target].append(directory)
        loads[target] += wall
    return {name: sorted(directories) for name, directories in bins.items()}


def validate(shards: dict[str, list[str]], surface: Path) -> None:
    claimed = [directory for directories in shards.values() for directory in directories]
    if len(claimed) != len(set(claimed)):
        raise SystemExit('Test shards overlap: a directory appears in more than one shard')
    present = feature_directories(surface)
    missing = sorted(present - set(claimed))
    extra = sorted(set(claimed) - present)
    if missing or extra:
        parts = []
        if missing:
            parts.append('unsharded directories: ' + ', '.join(missing))
        if extra:
            parts.append('unknown directories: ' + ', '.join(extra))
        raise SystemExit('; '.join(parts))
    for name, directories in shards.items():
        for directory in directories:
            path = surface / directory
            if not any(path.rglob('*Test.php')):
                raise SystemExit(f'shard {name!r} directory {directory!r} contains no *Test.php files')

    # Every test file must resolve to exactly one shard (#626).
    owner: dict[str, str] = {}
    for name, directories in shards.items():
        for directory in directories:
            for test in (surface / directory).rglob('*Test.php'):
                rel = test.relative_to(surface).as_posix()
                if rel in owner:
                    raise SystemExit(f'Test {rel} is claimed by shards {owner[rel]!r} and {name!r}')
                owner[rel] = name
    files = [path.relative_to(surface).as_posix() for path in feature_test_files(surface)]
    omitted = [path for path in files if path not in owner]
    if omitted:
        raise SystemExit('Test files missing from every shard: ' + ', '.join(omitted[:20]))


def paths_for(shard: str, shards: dict[str, list[str]], surface: Path, root: Path) -> list[str]:
    if shard not in shards:
        raise SystemExit(f'unknown test shard {shard!r}; known: {", ".join(sorted(shards))}')
    return [str((surface / directory).relative_to(root).as_posix()) for directory in shards[shard]]


def write_shards(path: Path, shards: dict[str, list[str]], timings: dict[str, float], timings_path: Path = TIMINGS_PATH) -> None:
    loads = {
        name: round(sum(timings[directory] for directory in directories), 3)
        for name, directories in shards.items()
    }
    payload = {
        'generated_from': timings_path.name,
        'balance': 'longest-processing-time',
        'estimated_wall_seconds': loads,
        'shards': shards,
    }
    path.write_text(json.dumps(payload, indent=2) + '\n', encoding='utf-8')


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT, help='repository root (tests live under tests/<suite>)')
    parser.add_argument('--suite', choices=['Feature', 'Unit'], default='Feature')
    parser.add_argument('--shards-file', type=Path)
    parser.add_argument('--timings-file', type=Path)
    parser.add_argument('--validate-only', action='store_true')
    parser.add_argument(
        '--write-balanced',
        action='store_true',
        help='regenerate --shards-file from --timings-file using LPT balance',
    )
    parser.add_argument('shard', nargs='?', help='shard id to emit (e.g. a)')
    args = parser.parse_args(argv)

    surface = args.root / 'tests' / args.suite
    args.shards_file = args.shards_file or Path(__file__).with_name(f'platform-{args.suite.lower()}-shards.json')
    args.timings_file = args.timings_file or Path(__file__).with_name(f'platform-{args.suite.lower()}-shard-timings.json')

    if args.write_balanced:
        timings = load_timings(args.timings_file)
        present = feature_directories(surface)
        missing = sorted(present - set(timings))
        extra = sorted(set(timings) - present)
        if missing or extra:
            parts = []
            if missing:
                parts.append('timings missing directories: ' + ', '.join(missing))
            if extra:
                parts.append('timings unknown directories: ' + ', '.join(extra))
            raise SystemExit('; '.join(parts))
        # Preserve existing shard ids when rewriting; default to a/b.
        existing = load_shards(args.shards_file) if args.shards_file.is_file() else {'a': ['AI'], 'b': ['Address']}
        shard_names = sorted(existing)
        shards = balance_from_timings(timings, shard_names)
        validate(shards, surface)
        write_shards(args.shards_file, shards, timings, args.timings_file)
        print(
            f'wrote {args.shards_file.relative_to(args.root)} '
            f'({len(shards)} shards; estimated walls '
            + ', '.join(f'{name}={sum(timings[d] for d in dirs):.3f}s' for name, dirs in shards.items())
            + ')'
        )
        return 0

    shards = load_shards(args.shards_file)
    validate(shards, surface)

    if args.validate_only:
        files = len(feature_test_files(surface))
        print(
            f'ok: {len(shards)} {args.suite} shards cover {sum(len(v) for v in shards.values())} '
            f'directories and {files} test files'
        )
        return 0

    if not args.shard:
        parser.error('shard id is required unless --validate-only or --write-balanced is set')

    for path in paths_for(args.shard, shards, surface, args.root):
        print(path)
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
