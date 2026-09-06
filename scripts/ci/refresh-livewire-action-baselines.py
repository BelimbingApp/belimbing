#!/usr/bin/env python3
"""Adopt a measured Livewire action snapshot only when its debt count decreases."""

import argparse
import json
from pathlib import Path


def read_snapshot(path: Path) -> dict:
    snapshot = json.loads(path.read_text())
    count = snapshot.get('module_owned_unreferenced')
    actions = snapshot.get('actions')
    if type(count) is not int or count < 0:
        raise ValueError(f'{path}: invalid debt count')
    if not isinstance(actions, list) or any(not isinstance(action, str) for action in actions):
        raise ValueError(f'{path}: invalid actions')
    if len(set(actions)) != count or len(actions) != count:
        raise ValueError(f'{path}: actions do not match the debt count')
    return snapshot


def refresh(baseline: Path, measured: Path) -> None:
    previous = read_snapshot(baseline)
    current = read_snapshot(measured)
    if previous.get('domain') != current.get('domain'):
        raise ValueError(f'{baseline}: measured scope does not match baseline')
    if current['module_owned_unreferenced'] >= previous['module_owned_unreferenced']:
        print(f'{baseline}: unchanged; measured debt did not decrease')
        return
    # An automated count ratchet must not remove an explicitly enabled name gate.
    if 'strict' in previous:
        current['strict'] = previous['strict']
    baseline.write_text(json.dumps(current, indent=4) + '\n')
    print(f'{baseline}: lowered debt to {current["module_owned_unreferenced"]}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('baseline', type=Path)
    parser.add_argument('measured', type=Path)
    args = parser.parse_args()
    refresh(args.baseline, args.measured)
