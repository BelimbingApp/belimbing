#!/usr/bin/env python3
"""Print the database-feature tests that must run on PostgreSQL.

The PostgreSQL lane already creates an isolated migrated database for this
surface. Discovering the files here means a new database feature test cannot
silently run only on SQLite because its author forgot a second workflow list.
"""

from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parents[2]
SURFACE = ROOT / 'tests' / 'Feature' / 'Database'


def main() -> int:
    tests = sorted(path for path in SURFACE.glob('*.php') if path.is_file())

    if not tests:
        print(f'No PostgreSQL-mirror tests found under {SURFACE.relative_to(ROOT)}.', file=sys.stderr)

        return 1

    for test in tests:
        print(test.relative_to(ROOT).as_posix())

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
