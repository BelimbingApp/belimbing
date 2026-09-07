#!/usr/bin/env python3
"""Apply a measured Livewire action-debt snapshot without ever raising the count (#775).

Workflows write a fresh snapshot via ``blb:livewire-actions --write-baseline``
into a temp path, then call this script to copy it onto the committed baseline
only when the measured ``module_owned_unreferenced`` count did not rise.
A higher count leaves the baseline file untouched and exits 0.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any


def load_snapshot(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise SystemExit(f"missing snapshot: {path}") from None
    except (OSError, json.JSONDecodeError) as error:
        raise SystemExit(f"invalid snapshot {path}: {error}") from None
    if not isinstance(payload, dict):
        raise SystemExit(f"{path}: snapshot must be a JSON object")
    count = payload.get("module_owned_unreferenced")
    if not isinstance(count, int) or isinstance(count, bool) or count < 0:
        raise SystemExit(
            f"{path}: module_owned_unreferenced must be a non-negative integer"
        )
    return payload


def apply_never_raise(baseline: Path, measured: dict[str, Any]) -> str:
    """Return 'wrote', 'unchanged-raise', or 'wrote-new'."""
    measured_count = measured["module_owned_unreferenced"]
    if baseline.is_file():
        existing = load_snapshot(baseline)
        old = existing["module_owned_unreferenced"]
        # never-raise guard: a higher measured count must not rewrite the file.
        if measured_count > old:
            print(
                f"refusing to raise Livewire action-debt baseline {baseline}: "
                f"{old} -> {measured_count}; leaving file untouched"
            )
            return "unchanged-raise"
    else:
        old = None

    baseline.parent.mkdir(parents=True, exist_ok=True)
    baseline.write_text(
        json.dumps(measured, indent=4, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    if old is None:
        print(f"wrote new Livewire action-debt baseline {baseline} at {measured_count}")
        return "wrote-new"
    print(
        f"wrote Livewire action-debt baseline {baseline}: {old} -> {measured_count}"
    )
    return "wrote"


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--baseline",
        type=Path,
        required=True,
        help="Committed baseline JSON path to update in place",
    )
    parser.add_argument(
        "--measured",
        type=Path,
        required=True,
        help="Fresh snapshot from blb:livewire-actions --write-baseline",
    )
    args = parser.parse_args(argv)
    apply_never_raise(args.baseline, load_snapshot(args.measured))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
