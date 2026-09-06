#!/usr/bin/env python3
"""Emit Feature-suite shard paths for platform CI (#576).

The Feature suite remains one logical suite in phpunit.xml. CI runs disjoint
directory shards concurrently; this script is the committed membership list
and the completeness check that refuses silent omission or double coverage.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SURFACE = ROOT / 'tests' / 'Feature'
SHARDS_PATH = Path(__file__).resolve().with_name('platform-feature-shards.json')


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


def feature_directories(surface: Path) -> set[str]:
    if not surface.is_dir():
        raise SystemExit(f'Feature surface missing: {surface}')
    dirs = {path.name for path in surface.iterdir() if path.is_dir()}
    loose = sorted(path.name for path in surface.glob('*Test.php'))
    if loose:
        raise SystemExit(
            'Feature tests must live under a first-level directory so shards can '
            f'own them; found loose files: {", ".join(loose)}'
        )
    return dirs


def validate(shards: dict[str, list[str]], surface: Path) -> None:
    claimed = [directory for directories in shards.values() for directory in directories]
    if len(claimed) != len(set(claimed)):
        raise SystemExit('Feature shards overlap: a directory appears in more than one shard')
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


def paths_for(shard: str, shards: dict[str, list[str]], surface: Path, root: Path) -> list[str]:
    if shard not in shards:
        raise SystemExit(f'unknown Feature shard {shard!r}; known: {", ".join(sorted(shards))}')
    return [str((surface / directory).relative_to(root).as_posix()) for directory in shards[shard]]


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT, help='repository root (tests live under tests/Feature)')
    parser.add_argument('--shards-file', type=Path, default=SHARDS_PATH)
    parser.add_argument('--validate-only', action='store_true')
    parser.add_argument('shard', nargs='?', help='shard id to emit (e.g. a)')
    args = parser.parse_args(argv)

    surface = args.root / 'tests' / 'Feature'
    shards = load_shards(args.shards_file)
    validate(shards, surface)

    if args.validate_only:
        print(f'ok: {len(shards)} Feature shards cover {sum(len(v) for v in shards.values())} directories')
        return 0

    if not args.shard:
        parser.error('shard id is required unless --validate-only is set')

    for path in paths_for(args.shard, shards, surface, args.root):
        print(path)
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
